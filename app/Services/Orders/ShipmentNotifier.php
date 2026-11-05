<?php

namespace App\Services\Orders;

use App\Models\OrderItem;
use App\Notifications\OrderShipped;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Every way of shipping (the order page, bought labels, the seller API) tells the buyer through here.
 * Lines that share a tracking number are one package, so they get one message.
 */
class ShipmentNotifier
{
    /** @param  Collection<int, OrderItem>  $items  lines that have just been marked shipped */
    public function notify(Collection $items): void
    {
        $packages = $items->groupBy(fn (OrderItem $item) => $item->order_id.'|'.$item->tracking_number);

        foreach ($packages as $package) {
            $order = $package->first()->order;

            // The order has already shipped by now: a mail problem must not make that look failed.
            try {
                $notification = new OrderShipped($order, $package->values());

                if ($order->buyer) {
                    $order->buyer->notify($notification);
                } elseif ($order->guest_email) {
                    Notification::route('mail', $order->guest_email)->notify($notification);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
