<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $table = 'product_subscriptions';

    protected $fillable = [
        'buyer_id', 'seller_id', 'product_id', 'product_variant_id', 'quantity',
        'interval_days', 'discount_pct', 'status', 'next_run_at', 'last_order_id',
        'orders_count', 'shipping_address',
    ];

    protected $casts = [
        'next_run_at' => 'datetime',
        'discount_pct' => 'decimal:2',
        'shipping_address' => 'array',
    ];

    public const INTERVALS = [30 => 'Every month', 60 => 'Every 2 months', 90 => 'Every 3 months', 180 => 'Every 6 months'];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function isDue(): bool
    {
        return $this->status === 'active' && $this->next_run_at !== null && $this->next_run_at->isPast();
    }

    public function intervalLabel(): string
    {
        return self::INTERVALS[$this->interval_days] ?? "Every {$this->interval_days} days";
    }

    /** Unit price after the subscription discount, against the product's current price. */
    public function unitPrice(): float
    {
        $base = $this->variant?->price ?? $this->product?->effectivePrice() ?? 0;

        return round($base * (1 - (float) $this->discount_pct / 100), 2);
    }
}
