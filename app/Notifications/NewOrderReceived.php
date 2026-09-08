<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Minimal stub — channels (mail/broadcast) are wired with the notification
 * system in Phase 7. Persists to the database so nothing is lost.
 */
class NewOrderReceived extends Notification
{
    use Queueable;

    public function __construct(public Order $order) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'new_order_received',
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
        ];
    }
}
