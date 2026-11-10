<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\AI\FraudScoringService;

/** Plain-language facts for the seller and admin order pages: risk, payment, address. */
class OrderInsight
{
    /** @return array{score: int, level: string, label: string, advice: string, reasons: list<string>} */
    public static function risk(Order $order): array
    {
        $result = app(FraudScoringService::class)->score($order);
        $score = (int) $result['risk_score'];
        $s = $result['signals'];

        $reasons = array_values(array_filter([
            $s['billing_shipping_mismatch'] ? 'Billing and shipping addresses are in different states' : null,
            $s['buyer_is_guest'] && $s['order_total'] > 500 ? 'Guest checkout over $500' : null,
            ! $s['buyer_is_guest'] && $s['buyer_account_age_days'] < 1 && $s['order_total'] > 300 ? 'Account under a day old, order over $300' : null,
        ]));

        [$level, $label, $advice] = match (true) {
            $score >= 50 => ['high', 'High', 'This order looks unusual. Our team reviews flagged orders. If you are unsure, contact support before you ship.'],
            $score >= 25 => ['medium', 'Medium', 'One thing stood out. Check the shipping address looks right before you ship.'],
            default => ['low', 'Low', 'Nothing unusual about this order. You can ship it as normal.'],
        };

        return compact('score', 'level', 'label', 'advice', 'reasons');
    }

    /** How the buyer paid: card, store credit, or both. */
    public static function paymentMethod(Order $order): string
    {
        $credit = (float) $order->credits_used > 0;
        $card = (float) $order->total > 0;

        return match (true) {
            $credit && $card => 'Card + store credit',
            $credit => 'Store credit',
            default => 'Card (Stripe)',
        };
    }

    public static function paymentStatus(OrderItem $item): string
    {
        return match ($item->status) {
            OrderStatus::Placed => 'Awaiting payment',
            OrderStatus::Cancelled => 'Cancelled',
            OrderStatus::Refunded => 'Refunded',
            default => 'Paid',
        };
    }

    /** One line for copying, and a Google Maps search link (opened only when someone clicks it). */
    public static function addressText(array $a): string
    {
        $street = trim(implode(', ', array_filter([$a['line1'] ?? $a['address_line1'] ?? null, $a['line2'] ?? $a['address_line2'] ?? null])));
        $city = trim(($a['city'] ?? '').(! empty($a['state']) ? ', '.$a['state'] : '').' '.($a['postal_code'] ?? $a['zip'] ?? ''));

        return implode(', ', array_filter([$a['name'] ?? null, $street, trim($city), $a['country'] ?? null]));
    }

    public static function mapsUrl(array $a): ?string
    {
        $query = trim(implode(', ', array_filter([
            $a['line1'] ?? $a['address_line1'] ?? null, $a['city'] ?? null, $a['state'] ?? null, $a['postal_code'] ?? $a['zip'] ?? null, $a['country'] ?? null,
        ])));

        return $query === '' ? null : 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($query);
    }
}
