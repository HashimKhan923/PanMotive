<?php

namespace App\Notifications;

use App\Models\Review;
use App\Notifications\Concerns\QueuesEmail;
use App\Notifications\Messages\PanmotiveMail;
use App\Support\EmailPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** Lets a buyer know the store replied to their review. */
class SellerRespondedToReview extends Notification implements ShouldQueue
{
    use Queueable, QueuesEmail;

    public function __construct(public Review $review) {}

    public function via(object $notifiable): array
    {
        return $notifiable->email && EmailPreferences::wants($notifiable, 'review_replies') ? ['mail', 'database'] : ['database'];
    }

    private function store(): string
    {
        return $this->review->product?->seller?->sellerProfile?->business_name ?? 'The seller';
    }

    public function toMail(object $notifiable): PanmotiveMail
    {
        return PanmotiveMail::to($notifiable)
            ->subject($this->store().' replied to your review')
            ->preheader(Str::limit((string) $this->review->seller_response, 120))
            ->line('About your review of "'.Str::limit((string) $this->review->product?->title, 80).'":')
            ->quote(Str::limit((string) $this->review->seller_response, 600), $this->store())
            ->action('See the reply', route('products.show', $this->review->product->slug).'#reviews')
            ->optional($notifiable, 'review_replies');
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
