<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Payments\CheckoutPaymentService;
use Illuminate\Support\Facades\DB;

/**
 * Orders that never get paid — a buyer who reached the payment page and left, or a
 * Subscribe & Save order their wallet couldn't cover — would otherwise hold their
 * stock forever and sit in the seller's queue as "awaiting payment". Cancel them
 * after a grace period and put the stock back.
 */
class UnpaidOrderService
{
    public function __construct(private OrderStockService $stock, private CheckoutPaymentService $payments) {}

    public function expireStale(): int
    {
        $cutoff = now()->subHours((int) config('marketplace.unpaid_order_hours', 48));
        $expired = 0;

        Order::where('status', OrderStatus::Placed->value)
            ->where('created_at', '<', $cutoff)
            ->with('items')
            ->chunkById(100, function ($orders) use (&$expired) {
                foreach ($orders as $order) {
                    $this->cancel($order, 'unpaid', 'Payment not received in time');
                    $expired++;
                }
            });

        return $expired;
    }

    /** Cancel an unpaid order: every still-unpaid line, the payment, and the reserved stock. */
    public function cancel(Order $order, string $reason, string $note): void
    {
        $this->payments->cancelIntent($order);

        DB::transaction(function () use ($order, $reason, $note) {
            $order->update(['status' => OrderStatus::Cancelled]);

            foreach ($order->items as $item) {
                if ($item->status !== OrderStatus::Placed) {
                    continue;
                }
                $item->update([
                    'status' => OrderStatus::Cancelled,
                    'cancelled_at' => now(),
                    'cancel_reason' => $reason,
                    'cancel_note' => $note,
                ]);
                $item->statusHistory()->create(['status' => OrderStatus::Cancelled->value, 'note' => $note]);
                $this->stock->release($item, "Order {$order->order_number}: {$note}");
            }
        });
    }
}
