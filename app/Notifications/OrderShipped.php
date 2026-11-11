<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\OrderItem;
use App\Notifications\Concerns\QueuesEmail;
use App\Notifications\Messages\PanmotiveMail;
use App\Support\OrderMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/** Tells the buyer a package is on its way, with its tracking number. One message per package. */
class OrderShipped extends Notification implements ShouldQueue
{
    use Queueable, QueuesEmail;

    /** @param  Collection<int, OrderItem>  $items  the lines in this package */
    public function __construct(public Order $order, public Collection $items) {}

    public function via(object $notifiable): array
    {
        // Guests are notified on-demand (mail only); account holders also get it in-app.
        return method_exists($notifiable, 'getKey') && $notifiable->getKey() ? ['mail', 'database'] : ['mail'];
    }

    public function toMail(object $notifiable): PanmotiveMail
    {
        $items = (new \Illuminate\Database\Eloquent\Collection($this->items->all()))->load('product.media', 'variant.media', 'seller.sellerProfile');
        $first = $items->first();
        $store = $first->seller?->sellerProfile?->business_name;

        $mail = PanmotiveMail::to($notifiable, $this->order->contactName())
            ->subject("Your order {$this->order->order_number} has shipped")
            ->preheader("Good news: your order is on its way. {$first->carrier} tracking {$first->tracking_number}.")
            ->line('Good news: your order is on its way'.($store ? " from {$store}" : '').'.')
            ->progress(1)
            ->facts([
                'Carrier' => $first->carrier,
                'Tracking number' => $first->tracking_number,
                'Order number' => $this->order->order_number,
            ])
            ->items(OrderMail::items($items, withStore: false), 'In this package');

        $orderUrl = OrderMail::orderUrl($this->order);

        if ($url = $first->trackingUrl()) {
            $mail->action('Track your package', $url)->line('See your order any time: '.$orderUrl);
        } else {
            $mail->action('View your order', $orderUrl);
        }

        return $mail->line('Tracking can take up to a day to show the first scan.')
            ->reason('You’re getting this email because you placed an order on Panmotive.');
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
