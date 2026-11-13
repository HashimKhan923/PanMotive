<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CampaignRecipient extends Model
{
    protected $fillable = [
        'marketing_campaign_id', 'user_id', 'token', 'sent_at', 'opened_at',
        'clicks', 'clicked_at', 'converted_order_id', 'conversion_value', 'failure', 'bounced_at', 'complained_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'opened_at' => 'datetime',
        'clicked_at' => 'datetime',
        'bounced_at' => 'datetime',
        'complained_at' => 'datetime',
        'conversion_value' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(fn (CampaignRecipient $r) => $r->token ??= Str::random(36));
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'marketing_campaign_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clicksLog(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CampaignClick::class);
    }
}
