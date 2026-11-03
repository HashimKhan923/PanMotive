<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AutomationSend extends Model
{
    protected $fillable = [
        'marketing_automation_id', 'user_id', 'dedupe_key', 'token',
        'opened_at', 'clicks', 'clicked_at', 'converted_order_id', 'conversion_value',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'clicked_at' => 'datetime',
        'conversion_value' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(fn (AutomationSend $s) => $s->token ??= Str::random(40));
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(MarketingAutomation::class, 'marketing_automation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
