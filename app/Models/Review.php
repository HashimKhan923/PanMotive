<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    protected $fillable = [
        'product_id', 'user_id', 'order_item_id', 'rating', 'title', 'body',
        'is_verified', 'status', 'helpful_count', 'seller_response', 'seller_responded_at',
        'report_reason', 'report_note', 'reported_at',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'rating' => 'integer',
        'seller_responded_at' => 'datetime',
        'reported_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::saved(fn (Review $r) => $r->product?->recalculateRatings());
        static::deleted(fn (Review $r) => $r->product?->recalculateRatings());
    }
}
