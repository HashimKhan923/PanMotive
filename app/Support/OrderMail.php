<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderItem;
use App\Notifications\Messages\PanmotiveMail;
use Illuminate\Support\Collection;

/** Turns orders into the rows the email building blocks show. */
class OrderMail
{
    /**
     * @param  iterable<OrderItem>  $items
     * @return list<array<string, mixed>>
     */
    public static function items(iterable $items, bool $withStore = true): array
    {
        return collect($items)->map(function (OrderItem $item) use ($withStore) {
            $store = $withStore ? ($item->seller?->sellerProfile?->business_name ?? $item->seller?->name) : null;

            return [
                'title' => $item->product_title,
                'meta' => implode(' · ', array_filter([
                    $item->variant_label,
                    $withStore ? null : ($item->product_sku ? 'SKU '.$item->product_sku : null),
                    $store ? 'Sold by '.$store : null,
                ])) ?: null,
                'qty' => (int) $item->quantity,
                'price' => PanmotiveMail::money($item->line_total),
                'image' => $item->imageUrl(),
                'url' => $item->product?->slug ? route('products.show', $item->product->slug) : null,
            ];
        })->values()->all();
    }

    /** @return list<array{0: string, 1: string, 2?: bool, 3?: bool}> */
    public static function totals(Order $order): array
    {
        $rows = [['Subtotal', PanmotiveMail::money($order->subtotal)]];
        $rows[] = ['Shipping', (float) $order->shipping_total > 0 ? PanmotiveMail::money($order->shipping_total) : 'Free'];
        if ((float) $order->discount_total > 0) {
            $rows[] = ['Discount', '−'.PanmotiveMail::money($order->discount_total), false, true];
        }
        if ((float) $order->tax_total > 0) {
            $rows[] = ['Sales tax', PanmotiveMail::money($order->tax_total)];
        }
        if ((float) $order->credits_used > 0) {
            $rows[] = ['Store credit', '−'.PanmotiveMail::money($order->credits_used), false, true];
        }
        $rows[] = ['Total paid', PanmotiveMail::money($order->total), true];

        return $rows;
    }

    /** @return list<string> */
    public static function addressLines(?array $a): array
    {
        if (! $a) {
            return [];
        }

        $street = trim(implode(', ', array_filter([$a['line1'] ?? $a['address_line1'] ?? null, $a['line2'] ?? $a['address_line2'] ?? null])));
        $city = trim(($a['city'] ?? '').(! empty($a['state']) ? ', '.$a['state'] : '').' '.($a['postal_code'] ?? $a['zip'] ?? ''));

        return array_values(array_filter([$a['name'] ?? null, $street, trim($city, ', '), ($a['country'] ?? 'US') !== 'US' ? ($a['country'] ?? null) : null]));
    }

    /** Where the buyer sees this order: their order page, or the lookup page for guests. */
    public static function orderUrl(Order $order): string
    {
        return $order->buyer_id ? route('orders.show', $order) : route('track.form');
    }

    /** @param  Collection<int, OrderItem>  $items */
    public static function storeCount(Collection $items): int
    {
        return $items->pluck('seller_id')->unique()->count();
    }
}
