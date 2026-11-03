<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyViolation extends Model
{
    protected $fillable = [
        'seller_id', 'type', 'severity', 'title', 'description', 'order_item_id',
        'status', 'issued_by', 'appeal_text', 'appeal_submitted_at',
        'appeal_decided_by', 'appeal_decided_at', 'expires_at',
    ];

    protected $casts = [
        'appeal_submitted_at' => 'datetime',
        'appeal_decided_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public const WEIGHTS = ['notice' => 1, 'warning' => 4, 'severe' => 12];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function isCounting(): bool
    {
        return in_array($this->status, ['active', 'appealed', 'upheld'], true)
            && (! $this->expires_at || $this->expires_at->isFuture());
    }

    public function weight(): int
    {
        return $this->isCounting() ? (self::WEIGHTS[$this->severity] ?? 4) : 0;
    }
}
