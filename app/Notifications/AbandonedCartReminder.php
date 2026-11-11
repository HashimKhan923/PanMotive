<?php

namespace App\Notifications;

use App\Models\Cart;
use App\Notifications\Concerns\QueuesEmail;
use App\Notifications\Messages\PanmotiveMail;
use App\Support\EmailPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class AbandonedCartReminder extends Notification implements ShouldQueue
{
    use Queueable, QueuesEmail;

    public function __construct(public Cart $cart) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return EmailPreferences::wants($notifiable, 'cart_reminders') ? ['mail'] : [];
    }

    public function toMail(object $notifiable): PanmotiveMail
    {
        $items = $this->cart->items()->with('product.media')->get();
        $itemCount = $items->sum('quantity');

        return PanmotiveMail::to($notifiable)
            ->subject("You left {$itemCount} item(s) in your cart")
            ->preheader('Your parts are still in your cart. Stock is limited.')
            ->line('Your parts are still waiting for you — complete your order before they sell out.')
            ->items($items->take(5)->map(fn ($i) => [
                'title' => $i->product?->title ?? 'Part',
                'qty' => (int) $i->quantity,
                'price' => $i->product ? PanmotiveMail::money($i->product->effectivePrice() * $i->quantity) : null,
                'image' => $i->product?->getFirstMediaUrl('images', 'thumb') ?: null,
                'url' => $i->product ? route('products.show', $i->product->slug) : null,
            ])->all())
            ->action('Complete Your Order', route('cart.index'))
            ->line('Need help finding the right fit? Reply to this email.')
            ->optional($notifiable, 'cart_reminders');
    }
}
