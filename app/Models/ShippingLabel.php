<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ShippingLabel extends Model
{
    protected $fillable = [
        'order_id', 'seller_id', 'carrier', 'service', 'tracking_number',
        'rate', 'retail_rate', 'label_url', 'provider', 'external_id', 'meta',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
        'retail_rate' => 'decimal:2',
        'meta' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(OrderItem::class, 'shipment_items');
    }

    public function savings(): float
    {
        return $this->retail_rate ? round((float) $this->retail_rate - (float) $this->rate, 2) : 0.0;
    }
}
