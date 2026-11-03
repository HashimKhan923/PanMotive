<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Offer extends Model
{
    protected $fillable = [
        'product_id', 'buyer_id', 'seller_id', 'quantity', 'offer_price',
        'counter_price', 'message', 'status', 'coupon_code', 'expires_at', 'responded_at',
    ];

    protected $casts = [
        'offer_price' => 'decimal:2',
        'counter_price' => 'decimal:2',
        'expires_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    /** The single-use code minted when this offer was accepted. */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_code', 'code');
    }

    public function isActionable(): bool
    {
        return $this->status === 'pending'
            && (! $this->expires_at || $this->expires_at->isFuture());
    }

    public function listPrice(): float
    {
        return (float) ($this->counter_price ?? $this->offer_price);
    }
}
