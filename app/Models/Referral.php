<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Referral extends Model
{
    protected $fillable = [
        'referrer_id', 'referred_user_id', 'code', 'status',
        'referrer_reward', 'referred_reward', 'completed_at',
    ];

    protected $casts = [
        'referrer_reward' => 'decimal:2',
        'referred_reward' => 'decimal:2',
        'completed_at' => 'datetime',
    ];

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }
}
