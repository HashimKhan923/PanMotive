<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoutAdvance extends Model
{
    protected $fillable = [
        'order_item_id', 'seller_id', 'gross', 'fee', 'net_paid',
        'stripe_transfer_id', 'status', 'recouped_at',
    ];

    protected $casts = [
        'gross' => 'decimal:2',
        'fee' => 'decimal:2',
        'net_paid' => 'decimal:2',
        'recouped_at' => 'datetime',
    ];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }
}
