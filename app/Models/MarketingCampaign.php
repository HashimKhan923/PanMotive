<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingCampaign extends Model
{
    protected $fillable = [
        'seller_id', 'channel', 'name', 'subject', 'preview', 'body',
        'audience', 'status', 'scheduled_at', 'sent_at', 'stats',
    ];

    protected $casts = [
        'audience' => 'array',
        'stats' => 'array',
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function rate(string $key): float
    {
        $s = $this->stats ?? [];
        $base = (int) ($s['recipients'] ?? 0);

        return $base > 0 ? round(((int) ($s[$key] ?? 0)) / $base * 100, 1) : 0.0;
    }
}
