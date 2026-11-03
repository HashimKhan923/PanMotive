<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleEventProduct extends Model
{
    public $timestamps = false;

    protected $fillable = ['sale_event_id', 'product_id', 'sale_price', 'previous_sale_price', 'previous_sale_starts_at', 'previous_deal_ends_at'];

    protected $casts = [
        'sale_price' => 'decimal:2', 'previous_sale_price' => 'decimal:2',
        'previous_sale_starts_at' => 'datetime', 'previous_deal_ends_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(SaleEvent::class, 'sale_event_id');
    }
}
