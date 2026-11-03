<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VolumeDiscount extends Model
{
    protected $fillable = ['product_id', 'min_quantity', 'discount_type', 'discount_value'];

    protected $casts = ['discount_value' => 'decimal:2'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Unit price for this tier given a base unit price. */
    public function unitPrice(float $base): float
    {
        $price = $this->discount_type === 'percentage'
            ? $base * (1 - (float) $this->discount_value / 100)
            : $base - (float) $this->discount_value;

        return round(max(0.01, $price), 2);
    }
}
