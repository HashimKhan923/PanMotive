<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'seller_id', 'product_id', 'product_title', 'product_sku',
        'quantity', 'unit_price', 'line_total', 'commission_rate',
        'platform_fee_amount', 'seller_payout_amount', 'status',
        'carrier', 'tracking_number', 'shipped_at', 'delivered_at', 'payout_released_at',
    ];

    protected $casts = [
        'status' => OrderStatus::class,
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'payout_released_at' => 'datetime',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
        'commission_rate' => 'decimal:2',
        'platform_fee_amount' => 'decimal:2',
        'seller_payout_amount' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    /** Computed once at creation — this is what Phase 4's payout job reads */
    public static function calculateCommission(float $lineTotal, float $rate): array
    {
        $fee = round($lineTotal * ($rate / 100), 2);

        return [
            'platform_fee_amount' => $fee,
            'seller_payout_amount' => round($lineTotal - $fee, 2),
        ];
    }
}
