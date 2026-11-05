<?php

namespace App\Services\Fulfilment;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SellerProfile;
use App\Models\ShippingLabel;
use App\Services\Integrations\WebhookDispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Real carrier labels through EasyPost. A shipment goes from the seller's ship-from address to the
 * buyer; the seller picks a live rate, we buy it, and the items ship with the real tracking number
 * and a printable label. Postage comes out of that order's payout (order_items.label_fee).
 *
 * Without an EasyPost key, labels are simply unavailable — sellers ship with their own account and
 * add tracking on the order. Tracking numbers are never invented.
 */
class LabelService
{
    private const API = 'https://api.easypost.com/v2';

    public static function available(): bool
    {
        return filled(config('services.easypost.key'));
    }

    /** @param  Collection<int, OrderItem>  $items */
    public function weightLbs(Collection $items): float
    {
        return (float) $items->sum(fn ($i) => ($i->product?->weight_lbs ?: 2) * $i->quantity);
    }

    /**
     * Create the EasyPost shipment and return its live rates, cheapest first.
     *
     * @param  Collection<int, OrderItem>  $items
     * @return array{shipment_id:string, rates:array<int, array{id:string, carrier:string, service:string, rate:float, retail:?float, days:?int}>}
     */
    public function quote(Order $order, Collection $items, SellerProfile $seller): array
    {
        $this->guard($items);
        if (! $seller->hasShipFrom()) {
            throw ValidationException::withMessages(['label' => 'Add your ship-from address in Store settings first.']);
        }

        $to = $order->shipping_address ?? [];
        $from = $seller->ship_from;

        $res = $this->http()->post(self::API.'/shipments', ['shipment' => [
            'to_address' => [
                'name' => $to['name'] ?? $order->buyer?->name, 'street1' => $to['line1'] ?? null, 'street2' => $to['line2'] ?? null,
                'city' => $to['city'] ?? null, 'state' => $to['state'] ?? null, 'zip' => $to['postal_code'] ?? null,
                'country' => $to['country'] ?? 'US', 'phone' => $to['phone'] ?? null,
            ],
            'from_address' => [
                'name' => ($from['name'] ?? null) ?: $seller->business_name, 'company' => ($from['company'] ?? null) ?: $seller->business_name,
                'street1' => $from['line1'], 'street2' => $from['line2'] ?? null, 'city' => $from['city'],
                'state' => $from['state'], 'zip' => $from['postal_code'], 'country' => 'US', 'phone' => $from['phone'] ?? null,
            ],
            'parcel' => ['weight' => max(1, round($this->weightLbs($items) * 16, 1))], // ounces
        ]]);

        if (! $res->successful()) {
            throw ValidationException::withMessages(['label' => 'Couldn\'t get rates: '.($res->json('error.message') ?? 'carrier error').'. Check both addresses.']);
        }

        $rates = collect($res->json('rates', []))
            ->map(fn ($r) => [
                'id' => $r['id'], 'carrier' => $r['carrier'], 'service' => $r['service'],
                'rate' => (float) $r['rate'], 'retail' => isset($r['retail_rate']) ? (float) $r['retail_rate'] : null,
                'days' => $r['delivery_days'] ?? null,
            ])
            ->sortBy('rate')->values()->all();

        if (! $rates) {
            throw ValidationException::withMessages(['label' => 'No carrier rates for this route — check the buyer\'s address.']);
        }

        return ['shipment_id' => $res->json('id'), 'rates' => $rates];
    }

    /**
     * Buy the chosen rate. Items are marked shipped with the real tracking number, and the postage
     * is charged to the seller by reducing this order's payout.
     *
     * @param  Collection<int, OrderItem>  $items
     */
    public function buy(Collection $items, string $shipmentId, string $rateId): ShippingLabel
    {
        $this->guard($items);
        $first = $items->first();

        $res = $this->http()->post(self::API."/shipments/{$shipmentId}/buy", ['rate' => ['id' => $rateId]]);
        if (! $res->successful() || ! $res->json('tracking_code')) {
            throw ValidationException::withMessages(['label' => 'The carrier didn\'t sell the label: '.($res->json('error.message') ?? 'try again').'.']);
        }

        $rate = $res->json('selected_rate', []);
        $cost = round((float) ($rate['rate'] ?? 0), 2);
        $tracking = $res->json('tracking_code');
        $carrier = $rate['carrier'] ?? 'Carrier';
        $service = $rate['service'] ?? null;

        $label = DB::transaction(function () use ($items, $first, $res, $rate, $cost, $tracking, $carrier, $service, $shipmentId) {
            $label = ShippingLabel::create([
                'order_id' => $first->order_id,
                'seller_id' => $first->seller_id,
                'carrier' => $carrier,
                'service' => $service,
                'tracking_number' => $tracking,
                'rate' => $cost,
                'retail_rate' => isset($rate['retail_rate']) ? (float) $rate['retail_rate'] : null,
                'label_url' => $res->json('postage_label.label_url'),
                'provider' => 'easypost',
                'external_id' => $shipmentId,
                'meta' => ['weight_lbs' => round($this->weightLbs($items), 2)],
            ]);

            // Spread the postage over the lines by value; the last takes the rounding remainder.
            $total = max((float) $items->sum('line_total'), 0.01);
            $remaining = $cost;
            foreach ($items->values() as $i => $item) {
                $share = $i === $items->count() - 1 ? $remaining : round($cost * (float) $item->line_total / $total, 2);
                $remaining = round($remaining - $share, 2);

                $label->items()->attach($item->id);
                $item->update([
                    'carrier' => $carrier,
                    'tracking_number' => $tracking,
                    'status' => OrderStatus::Shipped,
                    'shipped_at' => now(),
                    'label_fee' => round((float) $item->label_fee + $share, 2),
                    'seller_payout_amount' => round((float) $item->seller_payout_amount - $share, 2),
                ]);
                $item->statusHistory()->create([
                    'status' => OrderStatus::Shipped->value,
                    'note' => "Label purchased — {$carrier} {$service} ({$tracking}), \$".number_format($cost, 2).' postage',
                    'changed_by' => $first->seller_id,
                ]);
            }

            return $label;
        });

        foreach ($items as $item) {
            app(WebhookDispatcher::class)->fire('order.shipped', $item->seller_id, [
                'order_number' => $item->order?->order_number, 'item_id' => $item->id,
                'carrier' => $carrier, 'tracking_number' => $tracking,
            ]);
        }

        app(\App\Services\Orders\ShipmentNotifier::class)->notify($items);

        return $label;
    }

    /** @param  Collection<int, OrderItem>  $items */
    private function guard(Collection $items): void
    {
        if (! self::available()) {
            throw ValidationException::withMessages(['label' => 'Label buying isn\'t set up yet — ship with your own carrier account and add tracking on the order.']);
        }
        if ($items->isEmpty()
            || $items->pluck('order_id')->unique()->count() !== 1
            || $items->pluck('seller_id')->unique()->count() !== 1) {
            throw ValidationException::withMessages(['label' => 'A label covers items from one order and one seller.']);
        }
        if ($items->contains(fn ($i) => $i->status !== OrderStatus::SellerAccepted)) {
            throw ValidationException::withMessages(['label' => 'Accept every item first.']);
        }
    }

    private function http()
    {
        return Http::withBasicAuth((string) config('services.easypost.key'), '')->acceptJson()->asJson()->timeout(20);
    }
}
