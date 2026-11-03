<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\OrderItem;
use App\Models\PayoutAdvance;
use App\Models\User;
use App\Notifications\OrderItemCancelled;
use App\Services\Integrations\WebhookDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Seller-initiated cancellation of an unshipped order line: refunds exactly what
 * the buyer paid for it (net of their coupon, split between card and store
 * credit the same way they paid), puts the stock back, logs it, and tells the
 * buyer. If it leaves the order with nothing live, shipping is refunded too.
 */
class OrderCancellationService
{
    public const REASONS = [
        'out_of_stock' => 'Out of stock',
        'pricing_error' => 'Pricing error on the listing',
        'cannot_ship' => "Can't ship to this address",
        'buyer_requested' => 'Buyer asked to cancel',
        'suspected_fraud' => 'Suspected fraudulent order',
        'other' => 'Other',
    ];

    /** Reasons outside the seller's control don't count against their cancellation rate. */
    public const EXCUSED_REASONS = ['buyer_requested', 'suspected_fraud', 'unpaid', 'payment_failed'];

    public static function reasonLabel(?string $reason): string
    {
        return self::REASONS[$reason] ?? match ($reason) {
            'unpaid' => 'Buyer never paid',
            'payment_failed' => 'Payment failed',
            default => 'Cancelled',
        };
    }

    public function __construct(
        private OrderStockService $stock,
        private BuyerRefundService $refunds,
    ) {}

    /** @return float the amount refunded to the buyer */
    public function cancel(OrderItem $item, string $reason, ?string $note, ?User $actor): float
    {
        if (! array_key_exists($reason, self::REASONS)) {
            throw new RuntimeException('Choose a cancellation reason.');
        }
        if (! in_array($item->status, OrderStatus::cancellable(), true)) {
            throw new RuntimeException('Only orders that haven\'t shipped yet can be cancelled.');
        }
        if ($item->payout_released_at || PayoutAdvance::where('order_item_id', $item->id)->exists()) {
            throw new RuntimeException('You\'ve already been paid for this order — contact support to cancel it.');
        }

        $order = $item->order()->with('items')->first();
        $wasPaid = $item->status !== OrderStatus::Placed;

        $refunded = DB::transaction(function () use ($item, $order, $reason, $note, $actor, $wasPaid) {
            $lastLiveLine = $order->items
                ->reject(fn ($i) => $i->id === $item->id)
                ->every(fn ($i) => in_array($i->status, [OrderStatus::Cancelled, OrderStatus::Refunded], true));

            $refunded = $wasPaid
                ? $this->refunds->refundLine($item, 'seller_cancellation', "Refund for cancelled item on order {$order->order_number}", includeShipping: $lastLiveLine)
                : 0.0;

            $item->update([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
                'cancel_note' => $note,
            ]);

            $item->statusHistory()->create([
                'status' => OrderStatus::Cancelled->value,
                'note' => 'Cancelled by seller — '.self::REASONS[$reason]
                    .($note ? ": {$note}" : '')
                    .($refunded > 0 ? ' · $'.number_format($refunded, 2).' refunded' : ''),
                'changed_by' => $actor?->id,
            ]);

            $this->stock->release($item, "Order {$order->order_number} cancelled — ".self::REASONS[$reason]);

            if ($lastLiveLine) {
                $order->update(['status' => OrderStatus::Cancelled]);
            }

            return $refunded;
        });

        $notification = new OrderItemCancelled($item->fresh('order'), self::REASONS[$reason], $refunded);
        if ($order->buyer) {
            $order->buyer->notify($notification);
        } elseif ($order->guest_email) {
            Notification::route('mail', $order->guest_email)->notify($notification);
        }

        app(WebhookDispatcher::class)->fire('order.cancelled', $item->seller_id, [
            'order_item_id' => $item->id,
            'order_number' => $order->order_number,
            'reason' => $reason,
            'refunded' => $refunded,
        ]);

        return $refunded;
    }
}
