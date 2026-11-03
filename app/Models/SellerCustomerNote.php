<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerCustomerNote extends Model
{
    protected $fillable = ['seller_id', 'buyer_id', 'notes', 'tags'];

    protected $casts = ['tags' => 'array'];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }
}
