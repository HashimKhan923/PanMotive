<?php

namespace App\Notifications;

use App\Models\Order;
use App\Notifications\Concerns\QueuesEmail;
use App\Notifications\Messages\PanmotiveMail;
use App\Support\OrderInsight;
use App\Support\OrderMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** The buyer's receipt, sent once when the order is paid. Goes to guests too. */
class OrderConfirmation extends Notification implements ShouldQueue
{
    use Queueable, QueuesEmail;

    public function __construct(public Order $order) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): PanmotiveMail
    {
        $order = $this->order->loadMissing('items.product.media', 'items.variant.media', 'items.seller.sellerProfile');
        $stores = OrderMail::storeCount($order->items);

        $mail = PanmotiveMail::to($notifiable, $order->contactName())
            ->useWords('order_confirmation', ['order_number' => $order->order_number, 'order_total' => PanmotiveMail::money($order->total)])
            ->intro();

        if ($stores > 1) {
            $mail->line("Your parts come from {$stores} different stores, so they may arrive in separate packages.");
        }

        return $mail
            ->progress(0)
            ->facts([
                'Order number' => $order->order_number,
                'Order date' => ($order->placed_at ?? $order->created_at)->format('M j, Y'),
                'Payment' => OrderInsight::paymentMethod($order),
            ])
            ->items(OrderMail::items($order->items), 'Your order')
            ->totals(OrderMail::totals($order))
            ->facts(OrderMail::addressLines($order->shipping_address), 'Shipping to')
            ->when($order->buyer_id,
                fn ($m) => $m->button(OrderMail::orderUrl($order))->outro(),
                fn ($m) => $m->action('Track your order', OrderMail::orderUrl($order))
                    ->line('To check on this order later, use the Track an order page with your order number and this email address.'))
            ->reason('You’re getting this email because you placed an order on Panmotive.');
    }
}
