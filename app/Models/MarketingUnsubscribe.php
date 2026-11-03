<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A buyer's marketing opt-out — from one store (seller_id) or from all of them (seller_id null). */
class MarketingUnsubscribe extends Model
{
    protected $fillable = ['user_id', 'seller_id', 'source'];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }
}
