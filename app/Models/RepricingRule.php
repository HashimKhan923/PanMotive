<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepricingRule extends Model
{
    protected $fillable = [
        'product_id', 'seller_id', 'strategy', 'amount', 'floor_price',
        'ceiling_price', 'is_active', 'last_run_at', 'last_result',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'floor_price' => 'decimal:2',
        'ceiling_price' => 'decimal:2',
        'is_active' => 'boolean',
        'last_run_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
