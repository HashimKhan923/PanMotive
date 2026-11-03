<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OffsiteAdAttribution extends Model
{
    protected $fillable = [
        'order_id', 'seller_id', 'offsite_ad_click_id', 'channel', 'clicked_at',
        'attributed_sales', 'fee_rate', 'fee_amount',
    ];

    protected $casts = [
        'clicked_at' => 'datetime',
        'attributed_sales' => 'decimal:2',
        'fee_rate' => 'decimal:2',
        'fee_amount' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }
}
