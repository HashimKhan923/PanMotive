<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\User;
use App\Notifications\NewOrderReceived;
use App\Notifications\OrderConfirmation;
use Illuminate\Support\Facades\Notification;

/**
 * Every way an order gets paid (card via Stripe, store credit at checkout, a Subscribe & Save
 * order covered by the wallet) tells people through here: the buyer gets one receipt, and
 * each seller hears about their part. Safe to call twice: the receipt is only ever sent once.
 */
class OrderPaidNotifier
{
    public function notify(Order $order): void
    {
        $order->loadMissing('items', 'buyer');

        // Claim the receipt first, so a repeated Stripe webhook can't send a second one.
        $claimed = Order::whereKey($order->id)->whereNull('confirmation_sent_at')->update(['confirmation_sent_at' => now()]);
        if (! $claimed) {
            return;
        }

        try {
            $receipt = new OrderConfirmation($order);
            if ($order->buyer) {
                $order->buyer->notify($receipt);
            } elseif ($order->guest_email) {
                Notification::route('mail', $order->guest_email)->notify($receipt);
            }
        } catch (\Throwable $e) {
            report($e); // the order is paid either way; a mail problem must not undo that
        }

        foreach ($order->items->pluck('seller_id')->unique() as $sellerId) {
            try {
                User::find($sellerId)?->notify(new NewOrderReceived($order));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
