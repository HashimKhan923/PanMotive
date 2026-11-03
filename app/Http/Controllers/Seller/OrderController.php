<?php

namespace App\Http\Controllers\Seller;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Orders\OrderCancellationService;
use App\Support\SellerContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use RuntimeException;

class OrderController extends Controller
{
    /** Tab key => [label, statuses]. "placed" is kept as an alias of "new" for old notification links. */
    private function tabs(): array
    {
        return [
            'new' => ['New', [OrderStatus::Paid]],
            'seller_accepted' => ['To ship', [OrderStatus::SellerAccepted]],
            'shipped' => ['Shipped', [OrderStatus::Shipped, OrderStatus::OutForDelivery]],
            'completed' => ['Completed', [OrderStatus::Delivered, OrderStatus::PayoutReleased]],
            'returns' => ['Returns & disputes', [OrderStatus::ReturnRequested, OrderStatus::ReturnApproved, OrderStatus::Disputed]],
            'cancelled' => ['Cancelled', [OrderStatus::Cancelled, OrderStatus::Refunded]],
            'unpaid' => ['Awaiting payment', [OrderStatus::Placed]],
        ];
    }

    public function index(Request $request)
    {
        $sellerId = auth()->id();
        $tabs = $this->tabs();
        $status = $request->string('status')->toString();
        $status = $status === 'placed' ? 'new' : $status;

        $base = OrderItem::where('seller_id', $sellerId);
        $counts = ['all' => (clone $base)->count()];
        foreach ($tabs as $key => [, $statuses]) {
            $counts[$key] = (clone $base)->whereIn('status', array_map(fn ($s) => $s->value, $statuses))->count();
        }

        // Late = past its ship-by date (handling time from payment) and still not shipped.
        $lateIds = $this->lateIds($sellerId);
        $counts['late'] = count($lateIds);

        $items = $this->filtered($request, $status === 'late' ? '' : $status)
            ->when($status === 'late', fn ($q) => $q->whereIn('id', $lateIds ?: [0]))
            ->with('order:id,order_number,buyer_id,guest_name,created_at,placed_at', 'order.buyer:id,name', 'product:id,slug,handling_days,shipping_policy_id,seller_id', 'product.shippingPolicy')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('seller.orders.index', [
            'items' => $items,
            'counts' => $counts,
            'status' => $status,
            'tabs' => $tabs,
            'cancelReasons' => OrderCancellationService::REASONS,
        ]);
    }

    /** Download the current (filtered) order list as CSV. */
    public function export(Request $request)
    {
        $status = $request->string('status')->toString();
        $status = $status === 'placed' ? 'new' : $status;
        $rows = $this->filtered($request, $status)->with('order.buyer:id,name,email')->latest()->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Order', 'Date', 'Buyer', 'Product', 'Variant', 'SKU', 'Qty', 'Unit price', 'Line total',
                'Platform fee', 'Your payout', 'Status', 'Carrier', 'Tracking', 'Ship to']);
            foreach ($rows as $i) {
                $a = $i->order->shipping_address ?? [];
                fputcsv($out, [
                    $i->order->order_number,
                    $i->created_at->format('Y-m-d H:i'),
                    $i->order->contactName(),
                    $i->product_title,
                    $i->variant_label,
                    $i->product_sku,
                    $i->quantity,
                    number_format((float) $i->unit_price, 2, '.', ''),
                    number_format((float) $i->line_total, 2, '.', ''),
                    number_format((float) $i->platform_fee_amount, 2, '.', ''),
                    number_format((float) $i->seller_payout_amount, 2, '.', ''),
                    $i->status->label(),
                    $i->carrier,
                    $i->tracking_number,
                    trim(implode(', ', array_filter([$a['city'] ?? null, $a['state'] ?? null, $a['postal_code'] ?? null]))),
                ]);
            }
            fclose($out);
        }, 'orders-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function show(OrderItem $orderItem)
    {
        $this->authorizeOwnership($orderItem);

        $orderItem->load('order.buyer', 'product', 'statusHistory', 'returnRequests');

        // Everything else this buyer bought from this store in the same order.
        $siblings = OrderItem::where('order_id', $orderItem->order_id)
            ->where('seller_id', auth()->id())
            ->whereKeyNot($orderItem->id)
            ->get();

        return view('seller.orders.show', [
            'item' => $orderItem,
            'siblings' => $siblings,
            'cancelReasons' => OrderCancellationService::REASONS,
        ]);
    }

    /** Printable invoice covering just this store's lines on the order. */
    public function invoice(Order $order)
    {
        $items = $order->items()->where('seller_id', auth()->id())->get();
        abort_if($items->isEmpty(), 403);

        return view('seller.orders.invoice', [
            'order' => $order,
            'items' => $items,
            'store' => auth()->user()->sellerProfile,
        ]);
    }

    public function accept(OrderItem $orderItem)
    {
        $this->authorizeOwnership($orderItem);
        abort_unless(in_array($orderItem->status, OrderStatus::awaitingAcceptance(), true), 422,
            $orderItem->status === OrderStatus::Placed
                ? 'Payment for this order hasn\'t cleared yet.'
                : 'This order can no longer be accepted.');

        $orderItem->update(['status' => OrderStatus::SellerAccepted]);
        $this->logHistory($orderItem, OrderStatus::SellerAccepted);

        return back()->with('success', 'Order accepted — get it packed and shipped.')->with('celebrate', true);
    }

    /** Accept several new (paid) orders in one go. */
    public function bulkAccept(Request $request)
    {
        $ids = $request->validate(['ids' => 'required|array|max:100', 'ids.*' => 'integer'])['ids'];

        $items = OrderItem::where('seller_id', auth()->id())->whereIn('id', $ids)
            ->where('status', OrderStatus::Paid->value)->get();

        foreach ($items as $item) {
            $item->update(['status' => OrderStatus::SellerAccepted]);
            $this->logHistory($item, OrderStatus::SellerAccepted);
        }

        return back()->with('success', $items->count().' '.\Illuminate\Support\Str::plural('order', $items->count()).' accepted — they\'re now in "To ship".')
            ->with('celebrate', $items->isNotEmpty());
    }

    /** @return list<int> ids of this seller's unshipped lines that are past their ship-by date */
    private function lateIds(int $sellerId): array
    {
        return OrderItem::where('seller_id', $sellerId)
            ->whereIn('status', [OrderStatus::Paid->value, OrderStatus::SellerAccepted->value])
            ->with('order:id,placed_at', 'product:id,handling_days,shipping_policy_id,seller_id', 'product.shippingPolicy')
            ->get()
            ->filter(fn (OrderItem $i) => $i->isLate())
            ->pluck('id')->all();
    }

    public function cancel(Request $request, OrderItem $orderItem, OrderCancellationService $cancellations)
    {
        $this->authorizeOwnership($orderItem);

        $data = $request->validate([
            'reason' => 'required|in:'.implode(',', array_keys(OrderCancellationService::REASONS)),
            'note' => 'nullable|string|max:500',
            'cancel_all' => 'nullable|boolean',
        ]);

        $targets = $request->boolean('cancel_all')
            ? OrderItem::where('order_id', $orderItem->order_id)
                ->where('seller_id', auth()->id())
                ->whereIn('status', array_map(fn ($s) => $s->value, OrderStatus::cancellable()))
                ->get()
            : collect([$orderItem]);

        $refunded = 0.0;
        try {
            foreach ($targets as $target) {
                $refunded += $cancellations->cancel($target, $data['reason'], $data['note'] ?? null, SellerContext::actor());
            }
        } catch (RuntimeException $e) {
            return back()->withErrors(['cancel' => $e->getMessage()]);
        }

        $n = $targets->count();

        return back()->with('success', ($n > 1 ? "{$n} items cancelled" : 'Order cancelled')
            .($refunded > 0 ? ' — $'.number_format($refunded, 2).' refunded to the buyer.' : ' — the buyer has been notified.'));
    }

    public function ship(Request $request, OrderItem $orderItem)
    {
        $this->authorizeOwnership($orderItem);
        abort_unless($orderItem->status === OrderStatus::SellerAccepted, 422, 'This order is not ready to ship.');

        $validated = $request->validate([
            'carrier' => 'required|string|max:40',
            'tracking_number' => 'required|string|max:60',
            'ship_all' => 'nullable|boolean',
        ]);

        $targets = $request->boolean('ship_all')
            ? OrderItem::where('order_id', $orderItem->order_id)
                ->where('seller_id', auth()->id())
                ->where('status', OrderStatus::SellerAccepted->value)->get()
            : collect([$orderItem]);

        $dispatcher = app(\App\Services\Integrations\WebhookDispatcher::class);

        foreach ($targets as $item) {
            $item->update([
                'carrier' => $validated['carrier'],
                'tracking_number' => $validated['tracking_number'],
                'status' => OrderStatus::Shipped,
                'shipped_at' => now(),
            ]);
            $this->logHistory($item, OrderStatus::Shipped, "Shipped via {$validated['carrier']} — {$validated['tracking_number']}");
            $dispatcher->fire('order.shipped', $item->seller_id, [
                'order_item_id' => $item->id,
                'order_number' => $item->order->order_number,
                'carrier' => $item->carrier,
                'tracking_number' => $item->tracking_number,
            ]);
        }

        $n = $targets->count();

        return back()->with('success', $n > 1
            ? "{$n} items shipped together — one tracking number sent to the buyer."
            : 'Marked as shipped — tracking sent to the buyer.');
    }

    public function instantPayout(OrderItem $orderItem, \App\Services\Payments\InstantPayoutService $instant)
    {
        $this->authorizeOwnership($orderItem);

        $advance = $instant->advance($orderItem);

        return back()->with('success', "Paid \$".number_format($advance->net_paid, 2)." now (\${$advance->fee} fee). We'll recoup it when the buyer confirms delivery.")
            ->with('celebrate', true);
    }

    private function filtered(Request $request, string $status): Builder
    {
        $statuses = $this->tabs()[$status][1] ?? null;
        $term = trim((string) $request->input('q'));

        return OrderItem::where('seller_id', auth()->id())
            ->when($statuses, fn ($q) => $q->whereIn('status', array_map(fn ($s) => $s->value, $statuses)))
            ->when($term !== '', fn ($q) => $q->where(function ($w) use ($term) {
                $w->where('product_title', 'like', "%{$term}%")
                    ->orWhere('product_sku', 'like', "%{$term}%")
                    ->orWhere('tracking_number', 'like', "%{$term}%")
                    ->orWhereHas('order', fn ($o) => $o->where('order_number', 'like', "%{$term}%")
                        ->orWhere('guest_name', 'like', "%{$term}%")
                        ->orWhereHas('buyer', fn ($b) => $b->where('name', 'like', "%{$term}%")));
            }))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')));
    }

    protected function authorizeOwnership(OrderItem $orderItem): void
    {
        abort_unless($orderItem->seller_id === auth()->id(), 403);
    }

    protected function logHistory(OrderItem $orderItem, OrderStatus $status, ?string $note = null): void
    {
        $orderItem->statusHistory()->create([
            'status' => $status->value,
            'note' => $note,
            'changed_by' => auth()->id(),
        ]);
    }
}
