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

/** The carrier says the package arrived: what to do if something's wrong, and a nudge to review. */
class OrderDelivered extends Notification implements ShouldQueue
{
    use Queueable, QueuesEmail;

    /** @param  Collection<int, OrderItem>  $items  the lines in this package */
    public function __construct(public Order $order, public Collection $items) {}

    public function via(object $notifiable): array
    {
        return method_exists($notifiable, 'getKey') && $notifiable->getKey() ? ['mail', 'database'] : ['mail'];
    }

    public function toMail(object $notifiable): PanmotiveMail
    {
        $items = (new \Illuminate\Database\Eloquent\Collection($this->items->all()))->load('product.media', 'variant.media', 'seller.sellerProfile');
        $days = (int) config('marketplace.returns.window_days', 30);

        $mail = PanmotiveMail::to($notifiable, $this->order->contactName())
            ->success()
            ->subject("Delivered: your order {$this->order->order_number}")
            ->preheader('Your package has arrived. Tell us how it went.')
            ->line('Your package has been delivered. We hope the parts fit perfectly!')
            ->progress(2)
            ->items(OrderMail::items($items), 'Delivered')
            ->line("Something wrong? Wrong part, damaged, or not there? You can ask for a return from your order page within {$days} days.");

        if ($this->order->buyer_id) {
            $product = $items->first()?->product;
            $mail->action('Leave a review', $product ? route('products.show', $product->slug).'#reviews' : OrderMail::orderUrl($this->order))
                ->line('Reviews help other drivers find the right part, and help good sellers grow.');
        } else {
            $mail->action('View your order', OrderMail::orderUrl($this->order));
        }

        return $mail->reason('You’re getting this email because you placed an order on Panmotive.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'order_delivered',
            'title' => 'Your order was delivered',
            'body' => "Order {$this->order->order_number}",
            'order_id' => $this->order->id,
            'url' => route('orders.show', $this->order),
        ];
    }
}
