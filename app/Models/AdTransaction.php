<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdTransaction extends Model
{
    protected $fillable = [
        'seller_id', 'type', 'amount', 'balance_after', 'description',
        'source_type', 'source_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function source()
    {
        return $this->morphTo();
    }
}
