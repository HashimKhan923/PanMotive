<?php

namespace App\Notifications;

use App\Models\Offer;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class OfferActivity extends Notification
{
    use Queueable;

    /** @param 'received'|'countered'|'declined'|'accepted' $event */
    public function __construct(public Offer $offer, public string $event, public string $url) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $p = $this->offer->product;
        $price = number_format($this->offer->listPrice(), 2);

        $copy = match ($this->event) {
            'received' => ["New offer: \${$price} for {$p->title}", 'Review it before it expires in 48h.'],
            'countered' => ["Counter-offer: \${$price} for {$p->title}", 'The seller countered — accept or decline it on the listing within 48h.'],
            'declined' => ['Offer declined', "The seller passed on your offer for {$p->title}."],
            'accepted' => ["Offer accepted — \${$price}!", 'Tap "Buy now at your price" on the listing within 48h.'],
            'auto_accepted' => ["Offer auto-accepted: \${$price} for {$p->title}", 'It met your auto-accept price — the buyer has 48h to check out.'],
            'counter_accepted' => ["Counter accepted: \${$price} for {$p->title}", 'The buyer accepted — they have 48h to check out.'],
            'counter_declined' => ['Counter-offer declined', "The buyer passed on your counter for {$p->title}."],
            default => ['Offer update', ''],
        };

        return [
            'type' => 'offer',
            'title' => $copy[0],
            'body' => $copy[1],
            'url' => $this->url,
            'offer_id' => $this->offer->id,
        ];
    }
}
