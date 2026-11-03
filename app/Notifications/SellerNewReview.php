<?php

namespace App\Notifications;

use App\Models\Review;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class SellerNewReview extends Notification
{
    use Queueable;

    public function __construct(public Review $review) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'review',
            'title' => "New {$this->review->rating}★ review on {$this->review->product->title}",
            'body' => Str::limit($this->review->body ?? $this->review->title ?? '', 90),
            'url' => route('seller.reviews.index'),
            'review_id' => $this->review->id,
        ];
    }
}
