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
            ->useWords('unpaid_cancelled', ['order_number' => $this->order->order_number])
            ->intro()
            ->items(OrderMail::items($this->order->items()->with('product.media', 'variant.media', 'seller.sellerProfile')->get()))
            ->button(config('app.url'))
            ->outro()
            ->reason('You’re getting this email because you started an order on Panmotive.');
    }
}
