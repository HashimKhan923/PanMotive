<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SellerReferral extends Model
{
    protected $fillable = [
        'referrer_id', 'referred_id', 'code', 'status', 'reward_months',
        'commission_discount_pct', 'activated_at', 'ends_at',
    ];

    protected $casts = [
        'commission_discount_pct' => 'decimal:2',
        'activated_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (SellerReferral $r) => $r->code ??= strtoupper(Str::random(8)));
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_id');
    }
}
