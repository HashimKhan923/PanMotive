<?php

namespace App\Services\Payments;

use App\Models\OrderItem;
use Stripe\StripeClient;
use Stripe\Transfer;

class TransferPayoutService
{
    protected StripeClient $stripe;

    public function __construct()
    {
        $this->stripe = new StripeClient(config('services.stripe.secret') ?: 'sk_test_placeholder');
    }

    public function transferToSeller(OrderItem $orderItem, string $connectedAccountId): Transfer
    {
        return $this->stripe->transfers->create([
            'amount' => (int) round($orderItem->seller_payout_amount * 100),
            'currency' => 'usd',
            'destination' => $connectedAccountId,
            'transfer_group' => "order_{$orderItem->order_id}",
            'metadata' => [
                'order_item_id' => $orderItem->id,
                'order_id' => $orderItem->order_id,
                'commission_rate' => $orderItem->commission_rate,
                'platform_fee_amount' => $orderItem->platform_fee_amount,
            ],
        ], [
            // Stripe-level dedupe, belt-and-suspenders with our own payout_released_at guard.
            'idempotency_key' => "payout-order-item-{$orderItem->id}",
        ]);
    }
}
