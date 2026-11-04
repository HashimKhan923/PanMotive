<?php

namespace App\Services\Orders;

use App\Models\OrderItem;
use App\Models\PayoutAdvance;
use App\Services\Marketing\WalletService;
use App\Services\Payments\PayoutReversalService;
use App\Services\Payments\RefundService;
use RuntimeException;

/**
 * The one place that gives a buyer their money back for an order line — used by
 * seller cancellations and returns. It refunds exactly what the buyer paid (after
 * every discount), split the same way they paid (card vs store credit), and when
 * the seller had already been paid it pulls that payout back and reverses the
 * cashback the buyer earned on the purchase.
 */
class BuyerRefundService
{
    public function __construct(
        private RefundService $refunds,
        private WalletService $wallet,
        private PayoutReversalService $reversals,
    ) {}

    /** What the buyer actually paid for this line, after seller and platform discounts. */
    public function paidForLine(OrderItem $item, bool $includeShipping = false): float
    {
        $order = $item->order()->with('items')->first();

        // line_total already has any seller-funded discount (bundle / seller coupon) taken off;
        // what's left of the order discount is platform-funded and shared across all lines.
        $netSubtotal = max((float) $order->items->sum('line_total'), 0.01);
        $platformDiscount = max(0, (float) $order->discount_total - (float) $order->items->sum('discount_amount'));

        $amount = (float) $item->line_total * max(0, $netSubtotal - $platformDiscount) / $netSubtotal;
        if ($includeShipping) {
            $amount += (float) $order->shipping_total;
        }

        return round($amount, 2);
    }

    /**
     * Refund this line to the buyer. Call inside a DB transaction: if the card refund
     * fails it throws first, before anything else has changed.
     *
     * @return float amount refunded
     */
    public function refundLine(OrderItem $item, string $kind, string $description, bool $includeShipping = false): float
    {
        $order = $item->order;

        // An instant payout fronted to the seller can't be clawed back automatically.
        if (PayoutAdvance::where('order_item_id', $item->id)->where('status', 'advanced')->exists()) {
            throw new RuntimeException('You took an instant payout on this order — contact Panmotive support to refund it.');
        }

        $amount = $this->paidForLine($item, $includeShipping);

        // Split the same way they paid: card (order total) vs store credit (credits_used).
        $paidPool = (float) $order->total + (float) $order->credits_used;
        $creditShare = $order->stripe_payment_intent_id
            ? ($paidPool > 0 ? round($amount * (float) $order->credits_used / $paidPool, 2) : 0.0)
            : $amount; // no card payment at all — wallet/credit-covered order
        $cardShare = round($amount - $creditShare, 2);

        // The seller was already paid for this line → take that payout back first.
        if ($item->payout_released_at) {
            try {
                $this->reversals->reverseTransfer($item);
            } catch (\Stripe\Exception\ApiErrorException $e) {
                throw new RuntimeException('The seller payout could not be pulled back: '.$e->getMessage(), 0, $e);
            }
        }

        $this->refunds->refundAmount($order, $cardShare, ['order_item_id' => $item->id, 'kind' => $kind]);

        if ($creditShare > 0 && $order->buyer) {
            $this->wallet->credit($order->buyer, $creditShare, 'admin_adjustment', $description, $item);
        }

        $this->reverseCashback($item);

        return $amount;
    }

    /** Cashback paid on a purchase that's now refunded is taken back (as far as the balance allows). */
    private function reverseCashback(OrderItem $item): void
    {
        $buyer = $item->order?->buyer;
        if (! $buyer || ! $item->cashback_credited_at) {
            return;
        }

        $buyer->refresh();
        $take = round(min((float) $item->cashback_amount, (float) $buyer->wallet_balance), 2);
        if ($take > 0) {
            $this->wallet->credit($buyer, -$take, 'refund_reversal', "Cashback reversed — refund on order {$item->order->order_number}", $item);
        }
        $buyer->forceFill(['lifetime_spend' => max(0, (float) $buyer->lifetime_spend - (float) $item->line_total)])->save();
        $item->forceFill(['cashback_credited_at' => null])->save();
    }
}
