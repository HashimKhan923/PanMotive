<?php

namespace App\Notifications;

use App\Models\Product;
use App\Notifications\Concerns\QueuesEmail;
use App\Notifications\Messages\PanmotiveMail;
use App\Support\EmailPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** A part the shopper asked about is back in stock. */
class BackInStock extends Notification implements ShouldQueue
{
    use Queueable, QueuesEmail;

    public function __construct(public Product $product) {}

    public function via(object $notifiable): array
    {
        return ($notifiable->email ?? null) && EmailPreferences::wants($notifiable, 'back_in_stock') ? ['mail', 'database'] : ['database'];
    }

    public function toMail(object $notifiable): PanmotiveMail
    {
        $p = $this->product;

        return PanmotiveMail::to($notifiable)
            ->subject("Back in stock: {$p->title}")
            ->preheader('The part you asked about is available again. Stock is limited.')
            ->line('Good news: the part you asked us to watch is back in stock.')
            ->items([[
                'title' => $p->title,
                'meta' => 'Sold by '.($p->seller?->sellerProfile?->business_name ?? 'a Panmotive seller'),
                'price' => PanmotiveMail::money($p->effectivePrice()),
                'image' => $p->getFirstMediaUrl('images', 'thumb') ?: null,
                'url' => route('products.show', $p->slug),
            ]])
            ->action('Get it now', route('products.show', $p->slug))
            ->line('Stock can sell out again quickly, so we can’t hold it for you.')
            ->optional($notifiable, 'back_in_stock');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'back_in_stock',
            'title' => 'Back in stock: '.$this->product->title,
            'body' => 'The part you asked about is available again.',
            'url' => route('products.show', $this->product->slug),
            'product_id' => $this->product->id,
        ];
    }
}
