<?php

namespace App\Notifications;

use App\Models\Cart;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AbandonedCartReminder extends Notification
{
    use Queueable;

    public function __construct(public Cart $cart) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $itemCount = $this->cart->items->sum('quantity');

        return (new MailMessage)
            ->subject("You left {$itemCount} item(s) in your cart")
            ->line('Your parts are still waiting for you — complete your order before they sell out.')
            ->action('Complete Your Order', route('cart.index'))
            ->line('Need help finding the right fit? Reply to this email.');
    }
}
