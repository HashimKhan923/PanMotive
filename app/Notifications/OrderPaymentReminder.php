<?php

namespace App\Notifications;

use App\Models\Order;
use App\Notifications\Concerns\QueuesEmail;
use App\Notifications\Messages\PanmotiveMail;
use App\Support\OrderMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** The buyer started paying but didn't finish; their parts are held until the deadline. */
class OrderPaymentReminder extends Notification implements ShouldQueue
{
    use Queueable, QueuesEmail;

    public function __construct(public Order $order) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): PanmotiveMail
    {
        $deadline = $this->order->created_at->copy()->addHours((int) config('marketplace.unpaid_order_hours', 48));

        return PanmotiveMail::to($notifiable)
            ->useWords('payment_reminder', [
                'order_number' => $this->order->order_number, 'order_total' => PanmotiveMail::money($this->order->total),
                'deadline' => $deadline->format('l, M j \a\t g:i A'),
            ])
            ->intro()
            ->items(OrderMail::items($this->order->items()->with('product.media', 'variant.media', 'seller.sellerProfile')->get()))
            ->totals(OrderMail::totals($this->order))
            ->button(route('orders.pay', $this->order))
            ->outro()
            ->reason('You’re getting this email because you started an order on Panmotive.');
    }
}
