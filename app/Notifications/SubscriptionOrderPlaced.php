<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionOrderPlaced extends Notification
{
    use Queueable;

    public function __construct(public Order $order, public Subscription $subscription) {}

    private function paid(): bool
    {
        return $this->order->status->value === 'paid';
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        // An unpaid repeat order needs the buyer to act — email them, not just an in-app note.
        return $this->paid() ? ['database'] : ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $hours = (int) config('marketplace.unpaid_order_hours', 48);

        return (new MailMessage)
            ->subject("Your Subscribe & Save order {$this->order->order_number} is ready — please pay")
            ->line("Your repeat delivery of \"{$this->subscription->product?->title}\" is ready to ship.")
            ->line('Your store credit didn\'t cover it, so it needs a card payment of $'.number_format((float) $this->order->total, 2).'.')
            ->action('Pay now', route('orders.pay', $this->order))
            ->line("If it isn't paid within {$hours} hours this delivery is skipped — your subscription stays active.");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'subscription_order_placed',
            'title' => $this->paid() ? 'Subscription order placed' : 'Subscription order needs payment',
            'body' => ($this->subscription->product?->title ?? 'Your subscription').' · $'.number_format((float) $this->order->total, 2),
            'url' => $this->paid() ? route('orders.show', $this->order) : route('orders.pay', $this->order),
            'order_number' => $this->order->order_number,
            'order_id' => $this->order->id,
            'product' => $this->subscription->product?->title,
            'paid' => $this->paid(),
            'total' => (float) $this->order->total,
        ];
    }
}
