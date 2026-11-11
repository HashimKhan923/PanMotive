<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'order_number', 'buyer_id', 'guest_email', 'guest_name', 'status', 'subtotal', 'shipping_total',
        'tax_total', 'shipping_tax', 'tax_calculation_id', 'tax_transaction_id', 'discount_total', 'credits_used', 'coupon_id', 'subscription_id', 'total', 'currency',
        'shipping_address', 'billing_address', 'stripe_payment_intent_id',
        'placed_at', 'delivered_at', 'auto_confirm_at', 'confirmation_sent_at', 'payment_reminder_sent_at',
    ];

    protected $casts = [
        'status' => OrderStatus::class,
        'shipping_address' => 'array',
        'billing_address' => 'array',
        'placed_at' => 'datetime',
        'delivered_at' => 'datetime',
        'auto_confirm_at' => 'datetime',
        'confirmation_sent_at' => 'datetime',
        'payment_reminder_sent_at' => 'datetime',
    ];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    /** When every line is refunded or cancelled, the order as a whole says so. */
    public function syncStatusFromLines(): void
    {
        $lines = $this->items()->get(['id', 'status']);
        $closed = $lines->isNotEmpty() && $lines->every(fn ($l) => in_array($l->status, [OrderStatus::Refunded, OrderStatus::Cancelled], true));

        if ($closed) {
            $this->update(['status' => $lines->contains(fn ($l) => $l->status === OrderStatus::Refunded) ? OrderStatus::Refunded : OrderStatus::Cancelled]);
        }
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function isGuest(): bool
    {
        return is_null($this->buyer_id);
    }

    public function contactEmail(): ?string
    {
        return $this->buyer?->email ?? $this->guest_email;
    }

    public function contactName(): ?string
    {
        return $this->buyer?->name ?? $this->guest_name ?? ($this->shipping_address['name'] ?? null);
    }
}
