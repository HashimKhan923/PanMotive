<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Channels are wired in Phase 7/8: persisted to the database, and pushed to the
 * seller's subscribed devices via Web Push when they've opted in.
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
        $channels = ['database'];

        if (method_exists($notifiable, 'pushSubscriptions') && $notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('New order received')
            ->body("Order {$this->order->order_number} is waiting for you to accept.")
            ->action('View order', 'view_order')
            ->data(['url' => route('seller.orders.index')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'order',
            'title' => "New order {$this->order->order_number}",
            'body' => 'A buyer checked out — accept it to start fulfilment.',
            'url' => route('seller.orders.index', ['status' => 'new']),
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
        ];
    }
}
