<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccount extends Model
{
    protected $fillable = [
        'seller_id', 'platform', 'handle', 'external_id',
        'access_token', 'refresh_token', 'token_expires_at', 'status', 'meta',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'meta' => 'array',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function isLive(): bool
    {
        return $this->status === 'connected'
            && $this->access_token
            && ! str_starts_with((string) $this->access_token, 'demo_');
    }
}
