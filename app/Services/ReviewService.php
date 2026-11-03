<?php

namespace App\Services;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;

class ReviewService
{
    /** Has this user received (delivered/released) this product? */
    public function purchasedItem(User $user, Product $product): ?OrderItem
    {
        return OrderItem::where('product_id', $product->id)
            ->whereIn('status', ['delivered', 'payout_released'])
            ->whereHas('order', fn ($q) => $q->where('buyer_id', $user->id))
            ->latest()->first();
    }

    public function canReview(User $user, Product $product): bool
    {
        return $this->purchasedItem($user, $product) !== null
            && ! Review::where('product_id', $product->id)->where('user_id', $user->id)->exists();
    }

    public function submit(User $user, Product $product, int $rating, ?string $title, ?string $body): Review
    {
        $item = $this->purchasedItem($user, $product);

        $review = Review::updateOrCreate(
            ['product_id' => $product->id, 'user_id' => $user->id],
            [
                'order_item_id' => $item?->id,
                'rating' => max(1, min(5, $rating)),
                'title' => $title,
                'body' => $body,
                'is_verified' => (bool) $item,
                'status' => 'published',
            ]
        );

        if ($review->wasRecentlyCreated) {
            $product->loadMissing('seller');
            $product->seller?->notify(new \App\Notifications\SellerNewReview($review->setRelation('product', $product)));
        }

        return $review;
    }
}
