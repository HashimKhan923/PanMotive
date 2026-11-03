<?php

namespace App\Services\Seller;

use App\Models\AdminAlert;
use App\Models\Review;
use App\Notifications\SellerRespondedToReview;
use App\Services\AI\AiClient;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Seller side of product reviews: public responses, and asking Pan Motive to look at a rule-breaking review. */
class ReviewResponseService
{
    public const REPORT_REASONS = [
        'abusive' => 'Abusive, hateful or obscene language',
        'wrong_item' => 'About a different product or seller',
        'shipping_carrier' => 'Only about the shipping carrier (not my store)',
        'personal_info' => 'Contains personal or contact information',
        'spam' => 'Spam, promotion or fake review',
        'extortion' => 'Buyer is demanding something in exchange for changing it',
    ];

    public function respond(Review $review, string $response): void
    {
        $response = trim($response);
        if (Str::length($response) < 5) {
            throw ValidationException::withMessages(['response' => 'Write at least a short reply.']);
        }

        $first = $review->seller_response === null;
        $review->update(['seller_response' => $response, 'seller_responded_at' => now()]);

        // Tell the reviewer the store answered — once, not on every edit.
        if ($first && $review->user) {
            $review->user->notify(new SellerRespondedToReview($review->loadMissing('product')));
        }
    }

    public function remove(Review $review): void
    {
        $review->update(['seller_response' => null, 'seller_responded_at' => null]);
    }

    public function report(Review $review, string $reason, ?string $note): void
    {
        if (! array_key_exists($reason, self::REPORT_REASONS)) {
            throw ValidationException::withMessages(['reportReason' => 'Choose a reason.']);
        }
        if ($review->reported_at) {
            throw ValidationException::withMessages(['reportReason' => 'Already reported — Pan Motive will review it.']);
        }

        $review->update(['report_reason' => $reason, 'report_note' => $note ? trim($note) : null, 'reported_at' => now()]);

        AdminAlert::create([
            'type' => 'review_reported',
            'message' => "Seller reported review #{$review->id} ({$review->rating}★) on \"{$review->product?->title}\": "
                .self::REPORT_REASONS[$reason].($note ? " — {$note}" : ''),
            'related_order_item_id' => $review->order_item_id,
        ]);
    }

    /** Draft a calm, specific public reply. '' when AI isn't configured or fails. */
    public function draft(Review $review, string $store): string
    {
        $ai = app(AiClient::class);
        if (! $ai->isConfigured()) {
            return '';
        }

        try {
            return trim($ai->complete(
                "You write public replies to product reviews for {$store}, an auto-parts seller. 2–4 sentences, warm and professional. "
                .'Thank them; for critical reviews acknowledge the specific problem and invite them to message the store through Pan Motive. '
                .'Never argue, never promise refunds or discounts, never ask them to change their review, no contact details.',
                "Product: {$review->product?->title}\nRating: {$review->rating}/5\nTitle: {$review->title}\nReview: ".Str::limit((string) $review->body, 1200),
                220,
            ));
        } catch (\Throwable $e) {
            report($e);

            return '';
        }
    }
}
