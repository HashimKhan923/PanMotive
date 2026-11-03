<?php

namespace App\Services\Marketing;

use App\Models\Product;
use App\Models\SaleEvent;
use App\Models\SaleEventProduct;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Store-wide sales. A sale writes each listing's sale_price + window (so the storefront's existing
 * strike-through, deal badges and wishlist price-drop alerts all just work) and remembers what the
 * listing had before, so ending or cancelling the sale restores it exactly.
 */
class SaleEventService
{
    /**
     * @param  array<int, int>  $productIds
     * @return array{event: SaleEvent, skipped: array<int, string>}
     */
    public function create(User $seller, string $name, float $percentOff, Carbon $startsAt, Carbon $endsAt, array $productIds): array
    {
        if ($endsAt->lte($startsAt) || $endsAt->isPast()) {
            throw ValidationException::withMessages(['saleEnds' => 'The sale must end after it starts, and in the future.']);
        }

        $products = Product::where('seller_id', $seller->id)->whereIn('id', $productIds)->where('status', 'active')->get();
        if ($products->isEmpty()) {
            throw ValidationException::withMessages(['saleProducts' => 'Pick at least one active listing.']);
        }

        $skipped = [];

        $event = DB::transaction(function () use ($seller, $name, $percentOff, $startsAt, $endsAt, $products, &$skipped) {
            $event = SaleEvent::create([
                'seller_id' => $seller->id, 'name' => $name, 'percent_off' => $percentOff,
                'starts_at' => $startsAt, 'ends_at' => $endsAt,
                'status' => $startsAt->isFuture() ? 'scheduled' : 'active',
            ]);

            foreach ($products as $product) {
                // Don't stack on a listing that's already in another sale over these dates.
                $clash = SaleEventProduct::where('product_id', $product->id)
                    ->whereHas('event', fn ($q) => $q->whereIn('status', ['scheduled', 'active'])
                        ->where('id', '!=', $event->id)
                        ->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt))
                    ->exists();
                $ownDeal = $product->sale_price && (! $product->deal_ends_at || $product->deal_ends_at->isFuture())
                    && ! SaleEventProduct::where('product_id', $product->id)->whereHas('event', fn ($q) => $q->whereIn('status', ['scheduled', 'active']))->exists();

                $salePrice = round((float) $product->price * (1 - $percentOff / 100), 2);
                if ($clash || $ownDeal || $salePrice <= 0 || $salePrice >= (float) $product->price) {
                    $skipped[] = $product->title.($clash ? ' (in another sale)' : ($ownDeal ? ' (already has its own sale price)' : ''));

                    continue;
                }

                SaleEventProduct::create([
                    'sale_event_id' => $event->id,
                    'product_id' => $product->id,
                    'sale_price' => $salePrice,
                    'previous_sale_price' => $product->sale_price,
                    'previous_sale_starts_at' => $product->sale_starts_at,
                    'previous_deal_ends_at' => $product->deal_ends_at,
                ]);
                $product->update(['sale_price' => $salePrice, 'sale_starts_at' => $startsAt, 'deal_ends_at' => $endsAt]);
            }

            if (! $event->items()->exists()) {
                throw ValidationException::withMessages(['saleProducts' => 'None of those listings can join this sale: '.implode(', ', $skipped)]);
            }

            return $event;
        });

        return ['event' => $event, 'skipped' => $skipped];
    }

    /** Stop a sale now (or before it starts) and put every listing back as it was. */
    public function cancel(SaleEvent $event): void
    {
        DB::transaction(function () use ($event) {
            $this->restore($event);
            $event->update(['status' => 'cancelled', 'ends_at' => min($event->ends_at, now())]);
        });
    }

    /** Hourly: close out sales whose end date passed. */
    public function finishEnded(): int
    {
        $count = 0;
        SaleEvent::whereIn('status', ['scheduled', 'active'])->where('ends_at', '<=', now())->get()
            ->each(function (SaleEvent $event) use (&$count) {
                DB::transaction(function () use ($event) {
                    $this->restore($event);
                    $event->update(['status' => 'ended']);
                });
                $count++;
            });
        SaleEvent::where('status', 'scheduled')->where('starts_at', '<=', now())->update(['status' => 'active']);

        return $count;
    }

    /** Units sold and revenue on the sale's listings while it ran. @return array{orders:int, units:int, revenue:float} */
    public function performance(SaleEvent $event): array
    {
        $row = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.seller_id', $event->seller_id)
            ->whereIn('order_items.product_id', $event->items()->select('product_id'))
            ->whereNotIn('order_items.status', ['placed', 'cancelled', 'refunded'])
            ->whereBetween('orders.created_at', [$event->starts_at, min($event->ends_at, now())])
            ->selectRaw('COUNT(DISTINCT orders.id) o, COALESCE(SUM(order_items.quantity),0) u, COALESCE(SUM(order_items.line_total),0) r')
            ->first();

        return ['orders' => (int) $row->o, 'units' => (int) $row->u, 'revenue' => round((float) $row->r, 2)];
    }

    private function restore(SaleEvent $event): void
    {
        foreach ($event->items()->with('product')->get() as $item) {
            $p = $item->product;
            // Only undo it if the seller hasn't since set a different price by hand.
            if ($p && (float) $p->sale_price === (float) $item->sale_price) {
                $p->update([
                    'sale_price' => $item->previous_sale_price,
                    'sale_starts_at' => $item->previous_sale_starts_at,
                    'deal_ends_at' => $item->previous_deal_ends_at,
                ]);
            }
        }
    }
}
