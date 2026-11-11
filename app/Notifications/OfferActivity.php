<?php

namespace App\Notifications;

use App\Models\Offer;
use App\Notifications\Concerns\QueuesEmail;
use App\Notifications\Messages\PanmotiveMail;
use App\Support\EmailPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class OfferActivity extends Notification implements ShouldQueue
{
    use Queueable, QueuesEmail;

    /** Events the seller gets; the rest go to the buyer. */
    private const SELLER_EVENTS = ['received', 'auto_accepted', 'counter_accepted', 'counter_declined'];

    /** @param 'received'|'countered'|'declined'|'accepted' $event */
    public function __construct(public Offer $offer, public string $event, public string $url) {}

    private function forSeller(): bool
    {
        return in_array($this->event, self::SELLER_EVENTS, true);
    }

    private function category(): string
    {
        return $this->forSeller() ? 'seller_offers' : 'offers';
    }

    public function via(object $notifiable): array
    {
        return ($notifiable->email ?? null) && EmailPreferences::wants($notifiable, $this->category()) ? ['mail', 'database'] : ['database'];
    }

    /** @return array{0: string, 1: string} */
    private function copy(): array
    {
        $p = $this->offer->product;
        $price = number_format($this->offer->listPrice(), 2);

        return match ($this->event) {
            'received' => ["New offer: \${$price} for {$p->title}", 'Review it before it expires in 48h.'],
            'countered' => ["Counter-offer: \${$price} for {$p->title}", 'The seller countered — accept or decline it on the listing within 48h.'],
            'declined' => ['Offer declined', "The seller passed on your offer for {$p->title}."],
            'accepted' => ["Offer accepted — \${$price}!", 'Tap "Buy now at your price" on the listing within 48h.'],
            'auto_accepted' => ["Offer auto-accepted: \${$price} for {$p->title}", 'It met your auto-accept price — the buyer has 48h to check out.'],
            'counter_accepted' => ["Counter accepted: \${$price} for {$p->title}", 'The buyer accepted — they have 48h to check out.'],
            'counter_declined' => ['Counter-offer declined', "The buyer passed on your counter for {$p->title}."],
            default => ['Offer update', ''],
        };
    }

    public function toMail(object $notifiable): PanmotiveMail
    {
        [$title, $body] = $this->copy();
        $p = $this->offer->product;

        $mail = PanmotiveMail::to($notifiable)
            ->subject($title)
            ->preheader($body)
            ->line($body)
            ->items([[
                'title' => $p->title,
                'meta' => 'Listed at '.PanmotiveMail::money($p->effectivePrice()),
                'price' => PanmotiveMail::money($this->offer->listPrice()),
                'qty' => (int) ($this->offer->quantity ?? 1),
                'image' => $p->getFirstMediaUrl('images', 'thumb') ?: null,
                'url' => route('products.show', $p->slug),
            ]])
            ->action(match ($this->event) {
                'received' => 'Review the offer',
                'countered' => 'See the counter-offer',
                'accepted' => 'Buy now at your price',
                'declined' => 'See the listing',
                default => 'View offers',
            }, $this->url)
            ->optional($notifiable, $this->category());

        return $this->forSeller() ? $mail->forSeller() : $mail;
    }

    public function toArray(object $notifiable): array
    {
        [$title, $body] = $this->copy();

        return [
            'type' => 'offer',
            'title' => $title,
            'body' => $body,
            'url' => $this->url,
            'offer_id' => $this->offer->id,
        ];
    }
}
