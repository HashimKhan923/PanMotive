<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AffiliateProgram extends Model
{
    protected $fillable = ['seller_id', 'is_active', 'commission_pct', 'cookie_days', 'auto_approve', 'terms'];

    protected $casts = ['is_active' => 'boolean', 'auto_approve' => 'boolean', 'commission_pct' => 'decimal:2'];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function affiliates(): HasMany
    {
        return $this->hasMany(Affiliate::class);
    }
}
