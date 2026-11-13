<?php

namespace App\Notifications;

use App\Models\Product;
use App\Notifications\Concerns\QueuesEmail;
use App\Notifications\Messages\PanmotiveMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/** A buyer who hasn't ordered in a while: parts for their vehicle. Marketing, so it can be turned off. */
class WinBack extends Notification implements ShouldQueue
{
    use Queueable, QueuesEmail;

    /** @param  Collection<int, Product>  $products */
    public function __construct(public Collection $products, public ?string $vehicle = null, public ?string $shopUrl = null) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): PanmotiveMail
    {
        $mail = PanmotiveMail::to($notifiable);
        // The editable version is for buyers with a vehicle; others get a general one.
        $this->vehicle
            ? $mail->useWords('winback', ['vehicle' => $this->vehicle])->intro()
            : $mail->subject('Parts picked for you')->preheader('Fresh listings from Panmotive sellers.')
                ->line('It’s been a while! Here are some popular parts from Panmotive sellers. Add your vehicle to see only parts that fit.');

        if ($this->products->isNotEmpty()) {
            $mail->items($this->products->map(fn (Product $p) => [
                'title' => $p->title,
                'meta' => 'Sold by '.($p->seller?->sellerProfile?->business_name ?? 'a Panmotive seller'),
                'price' => PanmotiveMail::money($p->effectivePrice()),
                'image' => $p->getFirstMediaUrl('images', 'thumb') ?: null,
                'url' => route('products.show', $p->slug),
            ])->all());
        }

        return ($this->vehicle ? $mail->button($this->shopUrl ?? route('products.index'), 'Shop parts')->outro() : $mail->action('Shop parts', $this->shopUrl ?? route('products.index')))
            ->optional($notifiable, 'winback');
    }
}
