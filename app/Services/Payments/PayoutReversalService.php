<?php

namespace App\Services\Payments;

use App\Models\OrderItem;
use Stripe\StripeClient;

class PayoutReversalService
{
    protected StripeClient $stripe;

    public function __construct()
    {
        $this->stripe = new StripeClient(config('services.stripe.secret') ?: 'sk_test_placeholder');
    }

    public function reverse(OrderItem $orderItem): void
    {
        $this->reverseTransfer($orderItem);

        $this->stripe->refunds->create([
            'payment_intent' => $orderItem->order->stripe_payment_intent_id,
            'amount' => (int) round($orderItem->line_total * 100),
        ]);
    }

    /** Pull a released payout back from the seller's connected account (no buyer refund). */
    public function reverseTransfer(OrderItem $orderItem): void
    {
        // Find the original transfer via the transfer_group set in TransferPayoutService.
        $transfers = $this->stripe->transfers->all([
            'transfer_group' => "order_{$orderItem->order_id}",
        ]);

        $transfer = collect($transfers->data)->firstWhere('metadata.order_item_id', (string) $orderItem->id);

        if ($transfer) {
            $this->stripe->transfers->createReversal($transfer->id, [
                'amount' => (int) round($orderItem->seller_payout_amount * 100),
            ]);
        }
    }
}
