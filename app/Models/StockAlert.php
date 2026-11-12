<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Email me when it's back in stock." One per person per listing; sent once, then done. */
class StockAlert extends Model
{
    protected $fillable = ['user_id', 'product_id', 'notified_at'];

    protected $casts = ['notified_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
