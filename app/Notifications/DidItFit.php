<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\OrderItem;
use App\Notifications\Concerns\QueuesEmail;
use App\Notifications\Messages\PanmotiveMail;
use App\Support\OrderMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * A few days after delivery: did everything fit? Help first (return, Fit Guarantee, ask the
 * seller), then a review request. No reward is tied to the review, so it stays honest (FTC).
 */
class DidItFit extends Notification implements ShouldQueue
{
    use Queueable, QueuesEmail;

    /** @param  Collection<int, OrderItem>  $items  delivered lines the buyer hasn't reviewed */
    public function __construct(public Order $order, public Collection $items) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): PanmotiveMail
    {
        $items = (new \Illuminate\Database\Eloquent\Collection($this->items->all()))->load('product.media', 'variant.media', 'seller.sellerProfile');
        $first = $items->first();
        $guaranteed = $items->contains(fn ($i) => $i->fit_confirmed);

        $mail = PanmotiveMail::to($notifiable, $this->order->contactName())
            ->subject('Did everything fit?')
            ->preheader('Tell us how it went, or get help if something’s wrong.')
            ->line("Your order {$this->order->order_number} arrived a few days ago. We hope the install went smoothly!")
            ->items(OrderMail::items($items));

        $mail->line($guaranteed
            ? 'Something not right? Parts listed for your vehicle are covered by the Panmotive Fit Guarantee: if they don’t fit, you get a full refund and free return shipping. Start a return from your order page.'
            : 'Something not right? You can message the seller or start a return from your order page.');

        return $mail
            ->action('Write a review', $first?->product ? route('products.show', $first->product->slug).'#reviews' : OrderMail::orderUrl($this->order))
            ->line('Did it fit and work? A quick, honest review (good or bad) tells other owners of your vehicle whether this part is right for them.')
            ->line('Need help instead? Open your order: '.OrderMail::orderUrl($this->order))
            ->optional($notifiable, 'fit_checks');
    }
}
