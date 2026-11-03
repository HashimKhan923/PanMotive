<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdCampaign extends Model
{
    /** CPC by placement, debited from the seller's ad credit on each click. */
    public const CPC = ['search' => 0.25, 'category' => 0.15, 'home' => 0.40];

    protected $fillable = [
        'seller_id', 'name', 'objective', 'status', 'paused_for_credit', 'daily_budget', 'total_spend',
        'spent_today', 'spend_day', 'targeting', 'starts_at', 'ends_at',
    ];

    protected $casts = [
        'daily_budget' => 'decimal:2',
        'total_spend' => 'decimal:2',
        'spent_today' => 'decimal:2',
        'spend_day' => 'date',
        'targeting' => 'array',
        'paused_for_credit' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function creatives(): HasMany
    {
        return $this->hasMany(AdCreative::class);
    }

    public function scopeServable(Builder $q): Builder
    {
        return $q->where('status', 'active')
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function budgetToday(): float
    {
        if ($this->spend_day?->toDateString() !== now()->toDateString()) {
            return 0.0;
        }

        return (float) $this->spent_today;
    }

    public function hasBudgetLeft(): bool
    {
        return $this->budgetToday() < (float) $this->daily_budget;
    }

    public function targets(string $key): array
    {
        return $this->targeting[$key] ?? [];
    }

    public function metrics(): array
    {
        $c = $this->creatives;
        $impr = $c->sum('impressions');
        $clicks = $c->sum('clicks');
        $rev = (float) $c->sum('attributed_revenue');

        return [
            'impressions' => $impr,
            'clicks' => $clicks,
            'ctr' => $impr > 0 ? round($clicks / $impr * 100, 2) : 0.0,
            'orders' => $c->sum('attributed_orders'),
            'revenue' => $rev,
            'spend' => (float) $this->total_spend,
            'roas' => $this->total_spend > 0 ? round($rev / $this->total_spend, 2) : 0.0,
            'cpc' => $clicks > 0 ? round($this->total_spend / $clicks, 2) : 0.0,
        ];
    }
}
