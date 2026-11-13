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
            ->useWords('did_it_fit', ['order_number' => $this->order->order_number])
            ->intro()
            ->items(OrderMail::items($items));

        $mail->line($guaranteed
            ? 'Something not right? Parts listed for your vehicle are covered by the Panmotive Fit Guarantee: if they don’t fit, you get a full refund and free return shipping. Start a return from your order page.'
            : 'Something not right? You can message the seller or start a return from your order page.');

        return $mail
            ->button($first?->product ? route('products.show', $first->product->slug).'#reviews' : OrderMail::orderUrl($this->order))
            ->outro()
            ->line('Need help instead? Open your order: '.OrderMail::orderUrl($this->order))
            ->optional($notifiable, 'fit_checks');
    }
}
