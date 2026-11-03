<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A buyer this seller won't deal with (eBay's "blocked buyers list"): they can't message the
 * store, make offers, ask on its listings or check out its items.
 */
class SellerBlockedBuyer extends Model
{
    protected $fillable = ['seller_id', 'buyer_id', 'reason'];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public static function isBlocked(?int $sellerId, ?int $buyerId): bool
    {
        if (! $sellerId || ! $buyerId) {
            return false;
        }

        return static::where('seller_id', $sellerId)->where('buyer_id', $buyerId)->exists();
    }
}
