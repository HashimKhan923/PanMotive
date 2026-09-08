<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'order_number', 'buyer_id', 'status', 'subtotal', 'shipping_total',
        'tax_total', 'discount_total', 'credits_used', 'coupon_id', 'total', 'currency',
        'shipping_address', 'billing_address', 'stripe_payment_intent_id',
        'placed_at', 'delivered_at', 'auto_confirm_at',
    ];

    protected $casts = [
        'status' => OrderStatus::class,
        'shipping_address' => 'array',
        'billing_address' => 'array',
        'placed_at' => 'datetime',
        'delivered_at' => 'datetime',
        'auto_confirm_at' => 'datetime',
    ];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
