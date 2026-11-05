<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/** Tells the buyer a package is on its way, with its tracking number. One message per package. */
class OrderShipped extends Notification
{
    use Queueable;

    /** @param  Collection<int, OrderItem>  $items  the lines in this package */
    public function __construct(public Order $order, public Collection $items) {}

    public function via(object $notifiable): array
    {
        // Guests are notified on-demand (mail only); account holders also get it in-app.
        return method_exists($notifiable, 'getKey') && $notifiable->getKey() ? ['mail', 'database'] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $first = $this->items->first();

        $mail = (new MailMessage)
            ->subject("Your order {$this->order->order_number} has shipped")
            ->greeting('Hi '.($this->order->contactName() ?: 'there').',')
            ->line('Good news: your order is on its way.');

        foreach ($this->items as $item) {
            $mail->line("• {$item->product_title} (qty {$item->quantity})");
        }

        $mail->line("Carrier: {$first->carrier}")
            ->line("Tracking number: {$first->tracking_number}");

        // Guests have no order page to log in to; they look their order up on the tracking page.
        $orderUrl = $this->order->buyer_id ? route('orders.show', $this->order) : route('track.form');

        return $first->trackingUrl()
            ? $mail->action('Track your package', $first->trackingUrl())->line('See your order any time: '.$orderUrl)
            : $mail->action('View your order', $orderUrl);
    }

    public function toArray(object $notifiable): array
    {
        $first = $this->items->first();

        return [
            'type' => 'order_shipped',
            'title' => 'Your order has shipped',
            'body' => "Order {$this->order->order_number} · {$first->carrier} {$first->tracking_number}",
            'order_id' => $this->order->id,
            'url' => route('orders.show', $this->order),
        ];
    }
}
