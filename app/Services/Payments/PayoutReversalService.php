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

        // The seller's own transfer, not a Supply Network reseller margin paid for the same line.
        $transfer = collect($transfers->data)->first(fn ($t) => (string) data_get($t, 'metadata.order_item_id') === (string) $orderItem->id
            && data_get($t, 'metadata.kind') !== 'reseller_margin');

        if ($transfer) {
            // An instant payout sent less than the full payout (the fee was kept), and Stripe
            // refuses to reverse more than the transfer has left.
            $wanted = (int) round($orderItem->seller_payout_amount * 100);
            $left = (int) ($transfer->amount ?? $wanted) - (int) ($transfer->amount_reversed ?? 0);
            $amount = min($wanted, $left);

            if ($amount > 0) {
                $this->stripe->transfers->createReversal($transfer->id, ['amount' => $amount]);
            }
        }
    }

    /** Supply Network: pull back a reseller margin that was already paid for a line now refunded. */
    public function reverseResellerMargin(OrderItem $orderItem): void
    {
        if (! $orderItem->reseller_transfer_id) {
            return;
        }
        $transfer = $this->stripe->transfers->retrieve($orderItem->reseller_transfer_id);
        $left = (int) ($transfer->amount ?? 0) - (int) ($transfer->amount_reversed ?? 0);
        if ($left > 0) {
            $this->stripe->transfers->createReversal($transfer->id, ['amount' => $left]);
        }
    }
}
