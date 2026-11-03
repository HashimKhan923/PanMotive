<?php

namespace App\Services\Payments;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class RefundService
{
    protected StripeClient $stripe;

    public function __construct()
    {
        $this->stripe = new StripeClient(config('services.stripe.secret') ?: 'sk_test_placeholder');
    }

    public function refund(OrderItem $orderItem): void
    {
        // Guard: if payout was already released to the seller, this needs a
        // reversal Transfer as well as the buyer Refund — flagged for the Phase 5
        // admin UI to handle explicitly rather than silently auto-reversing.
        if ($orderItem->payout_released_at) {
            throw new RuntimeException('Payout already released — requires manual seller reversal, escalate to admin.');
        }

        $this->stripe->refunds->create([
            'payment_intent' => $orderItem->order->stripe_payment_intent_id,
            'amount' => (int) round($orderItem->line_total * 100),
        ]);

        $orderItem->update(['status' => OrderStatus::Refunded]);
    }

    /** Refund an arbitrary amount of an order's card payment, without touching any statuses. */
    public function refundAmount(Order $order, float $amount, array $metadata = []): void
    {
        if ($amount <= 0 || ! $order->stripe_payment_intent_id) {
            return;
        }

        try {
            $this->stripe->refunds->create([
                'payment_intent' => $order->stripe_payment_intent_id,
                'amount' => (int) round($amount * 100),
                'metadata' => $metadata,
            ]);
        } catch (ApiErrorException $e) {
            throw new RuntimeException('The card refund could not be processed: '.$e->getMessage(), 0, $e);
        }
    }
}
