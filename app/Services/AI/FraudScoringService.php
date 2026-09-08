<?php

namespace App\Services\AI;

use App\Models\Order;

class FraudScoringService
{
    public function __construct(private AiClient $ai) {}

    /**
     * @return array{risk_score: int, signals: array<string, mixed>, flagged: bool}
     */
    public function score(Order $order): array
    {
        $shipping = $order->shipping_address;
        $billing = $order->billing_address;

        $signals = [
            'billing_shipping_mismatch' => ($shipping['state'] ?? null) !== ($billing['state'] ?? null),
            'order_total' => (float) $order->total,
            'buyer_account_age_days' => $order->buyer?->created_at?->diffInDays(now()) ?? 0,
            'buyer_is_guest' => is_null($order->buyer_id),
        ];

        // Deterministic scoring for common signals — cheap and instant.
        $riskScore = 0;
        if ($signals['billing_shipping_mismatch']) {
            $riskScore += 30;
        }
        if ($signals['buyer_is_guest'] && $signals['order_total'] > 500) {
            $riskScore += 25;
        }
        if ($signals['buyer_account_age_days'] < 1 && $signals['order_total'] > 300) {
            $riskScore += 20;
        }

        return [
            'risk_score' => min($riskScore, 100),
            'signals' => $signals,
            'flagged' => $riskScore >= 50,
        ];
    }
}
