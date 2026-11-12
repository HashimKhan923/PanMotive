<?php

namespace App\Notifications;

use App\Models\Product;
use App\Notifications\Concerns\QueuesEmail;
use App\Notifications\Messages\PanmotiveMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/** Parts the shopper looked at recently but didn't buy. */
class BrowseReminder extends Notification implements ShouldQueue
{
    use Queueable, QueuesEmail;

    /** @param  Collection<int, Product>  $products */
    public function __construct(public Collection $products) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): PanmotiveMail
    {
        $first = $this->products->first();

        return PanmotiveMail::to($notifiable)
            ->subject($this->products->count() > 1 ? 'Still looking for the right part?' : "Still thinking about the {$first->title}?")
            ->preheader('The parts you looked at are still in stock.')
            ->line('You looked at these parts recently. They’re still in stock if you need them.')
            ->items($this->products->map(fn (Product $p) => [
                'title' => $p->title,
                'meta' => 'Sold by '.($p->seller?->sellerProfile?->business_name ?? 'a Panmotive seller'),
                'price' => PanmotiveMail::money($p->effectivePrice()),
                'image' => $p->getFirstMediaUrl('images', 'thumb') ?: null,
                'url' => route('products.show', $p->slug),
            ])->all())
            ->action('Take another look', route('products.show', $first->slug))
            ->line('Not sure it fits? Add your vehicle and look for the green “Fits your vehicle” check. Those parts are covered by the Panmotive Fit Guarantee.')
            ->optional($notifiable, 'browse_reminders');
    }
}
