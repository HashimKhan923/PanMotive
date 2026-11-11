<?php

namespace App\Notifications;

use App\Models\BuyerClaim;
use App\Notifications\Concerns\QueuesEmail;
use App\Notifications\Messages\PanmotiveMail;
use App\Support\OrderMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** The seller refunded the buyer to settle their claim. */
class ClaimResolvedBySeller extends Notification implements ShouldQueue
{
    use Queueable, QueuesEmail;

    public function __construct(public BuyerClaim $claim, public float $refunded) {}

    public function via(object $notifiable): array
    {
        return $notifiable->email ? ['mail', 'database'] : ['database'];
    }

    public function toMail(object $notifiable): PanmotiveMail
    {
        $item = $this->claim->orderItem;

        return PanmotiveMail::to($notifiable)
            ->subject("Your claim {$this->claim->claim_number} was resolved — refund on its way")
            ->preheader('The seller refunded '.PanmotiveMail::money($this->refunded).'.')
            ->line("The seller refunded \$".number_format($this->refunded, 2)." for \"{$item->product_title}\".")
            ->items(OrderMail::items([$item]))
            ->facts(['Claim' => $this->claim->claim_number, 'Refunded' => PanmotiveMail::money($this->refunded)])
            ->line('Card refunds usually appear within 5–10 business days; store credit is available right away.')
            ->action('View your order', route('orders.show', $item->order_id))
            ->reason('You’re getting this email because you opened a claim on Panmotive.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'claim_resolved',
            'title' => "Claim {$this->claim->claim_number} resolved — \$".number_format($this->refunded, 2).' refunded',
            'body' => $this->claim->orderItem?->product_title,
            'claim_id' => $this->claim->id,
        ];
    }
}
