<?php

namespace App\Notifications;

use App\Models\OrderItem;
use App\Notifications\Concerns\QueuesEmail;
use App\Notifications\Messages\PanmotiveMail;
use App\Support\OrderMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** Tells the buyer a seller cancelled part (or all) of their order, and what was refunded. */
class OrderItemCancelled extends Notification implements ShouldQueue
{
    use Queueable, QueuesEmail;

    public function __construct(public OrderItem $item, public string $reasonLabel, public float $refunded) {}

    public function via(object $notifiable): array
    {
        // Guests are notified on-demand (mail only); account holders also get it in-app.
        return method_exists($notifiable, 'getKey') && $notifiable->getKey() ? ['mail', 'database'] : ['mail'];
    }

    public function toMail(object $notifiable): PanmotiveMail
    {
        $order = $this->item->order;

        $mail = PanmotiveMail::to($notifiable, $order->contactName())
            ->useWords('item_cancelled', [
                'order_number' => $order->order_number, 'product' => $this->item->product_title, 'reason' => $this->reasonLabel,
                'refund' => $this->refunded > 0 ? PanmotiveMail::money($this->refunded) : '$0.00',
            ])
            ->intro()
            ->items(OrderMail::items([$this->item]))
            ->facts([
                'Reason' => $this->reasonLabel,
                'Refund' => $this->refunded > 0 ? PanmotiveMail::money($this->refunded) : 'Not charged',
            ]);

        $mail->line($this->refunded > 0
            ? 'A refund of $'.number_format($this->refunded, 2).' has been issued to your original payment method / store credit. Card refunds usually appear within 5–10 business days.'
            : 'You were not charged for this item.');

        return $mail->when($order->buyer_id,
                fn ($m) => $m->button(OrderMail::orderUrl($order)),
                fn ($m) => $m->action('Track your order', OrderMail::orderUrl($order)))
            ->outro()
            ->reason('You’re getting this email because you placed an order on Panmotive.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'order_cancelled',
            'title' => "Item cancelled: {$this->item->product_title}",
            'body' => "Order {$this->item->order->order_number} — {$this->reasonLabel}"
                .($this->refunded > 0 ? ' · $'.number_format($this->refunded, 2).' refunded' : ''),
            'order_item_id' => $this->item->id,
        ];
    }
}
