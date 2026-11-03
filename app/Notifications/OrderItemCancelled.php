<?php

namespace App\Notifications;

use App\Models\OrderItem;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the buyer a seller cancelled part (or all) of their order, and what was refunded. */
class OrderItemCancelled extends Notification
{
    use Queueable;

    public function __construct(public OrderItem $item, public string $reasonLabel, public float $refunded) {}

    public function via(object $notifiable): array
    {
        // Guests are notified on-demand (mail only); account holders also get it in-app.
        return method_exists($notifiable, 'getKey') && $notifiable->getKey() ? ['mail', 'database'] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->item->order;

        $mail = (new MailMessage)
            ->subject("Item cancelled from order {$order->order_number}")
            ->greeting('Hi '.($order->contactName() ?: 'there').',')
            ->line("The seller had to cancel \"{$this->item->product_title}\" from your order {$order->order_number}.")
            ->line("Reason: {$this->reasonLabel}.");

        if ($this->refunded > 0) {
            $mail->line('A refund of $'.number_format($this->refunded, 2).' has been issued to your original payment method / store credit. Card refunds usually appear within 5–10 business days.');
        } else {
            $mail->line('You were not charged for this item.');
        }

        return $mail->line('We\'re sorry for the inconvenience.');
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
