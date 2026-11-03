<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class BuyerClaim extends Model
{
    protected $fillable = [
        'claim_number', 'order_item_id', 'buyer_id', 'seller_id', 'reason', 'detail',
        'amount', 'status', 'seller_response', 'seller_responded_at', 'respond_by',
        'decided_by', 'decided_at', 'resolution_note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'seller_responded_at' => 'datetime',
        'respond_by' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public const REASONS = [
        'not_received' => "Item never arrived",
        'not_as_described' => "Item not as described",
        'damaged' => "Item arrived damaged or defective",
        'no_seller_response' => "Seller isn't responding",
    ];

    protected static function booted(): void
    {
        static::creating(function (BuyerClaim $c) {
            $c->claim_number ??= 'CLM-'.strtoupper(Str::random(9));
            $c->respond_by ??= now()->addHours(72);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'claim_number';
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    /**
     * Claims that count against the store's record: not denied, not withdrawn, and not ones the
     * seller resolved themselves by refunding before Pan Motive had to step in.
     */
    public function scopeCountingAgainstSeller(Builder $q): Builder
    {
        return $q->whereNotIn('status', ['denied', 'withdrawn'])
            ->where(fn ($w) => $w->where('status', '!=', 'granted')
                ->orWhereNull('decided_by')
                ->orWhereColumn('decided_by', '!=', 'seller_id'));
    }

    public function resolvedBySeller(): bool
    {
        return $this->status === 'granted' && $this->decided_by === $this->seller_id;
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'seller_responded', 'escalated'], true);
    }

    public function isOverdue(): bool
    {
        return $this->status === 'open' && $this->respond_by && $this->respond_by->isPast();
    }
}
