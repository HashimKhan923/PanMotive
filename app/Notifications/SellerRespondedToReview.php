<?php

namespace App\Notifications;

use App\Models\Review;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** Lets a buyer know the store replied to their review. */
class SellerRespondedToReview extends Notification
{
    use Queueable;

    public function __construct(public Review $review) {}

    public function via(object $notifiable): array
    {
        return $notifiable->email ? ['mail', 'database'] : ['database'];
    }

    private function store(): string
    {
        return $this->review->product?->seller?->sellerProfile?->business_name ?? 'The seller';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->store().' replied to your review')
            ->line('About your review of "'.Str::limit((string) $this->review->product?->title, 80).'":')
            ->line('"'.Str::limit((string) $this->review->seller_response, 400).'"')
            ->action('See the reply', route('products.show', $this->review->product->slug).'#reviews');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'review_response',
            'title' => $this->store().' replied to your review',
            'body' => Str::limit((string) $this->review->seller_response, 120),
            'url' => route('products.show', $this->review->product->slug).'#reviews',
        ];
    }
}
