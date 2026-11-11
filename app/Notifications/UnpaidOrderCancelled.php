<?php

namespace App\Notifications;

use App\Models\Order;
use App\Notifications\Concerns\QueuesEmail;
use App\Notifications\Messages\PanmotiveMail;
use App\Support\OrderMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** An order was never paid, so we cancelled it. Nothing was charged. */
class UnpaidOrderCancelled extends Notification implements ShouldQueue
{
    use Queueable, QueuesEmail;

    public function __construct(public Order $order) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): PanmotiveMail
    {
        return PanmotiveMail::to($notifiable, $this->order->contactName())
            ->subject("Order {$this->order->order_number} was cancelled")
            ->preheader('We didn’t receive payment, so you weren’t charged.')
            ->line('We didn’t receive payment for this order in time, so we cancelled it. You have not been charged.')
            ->items(OrderMail::items($this->order->items()->with('product.media', 'variant.media', 'seller.sellerProfile')->get()))
            ->action('Shop these parts again', config('app.url'))
            ->line('Still need them? Add them to your cart again. They may still be in stock.')
            ->reason('You’re getting this email because you started an order on Panmotive.');
    }
}
