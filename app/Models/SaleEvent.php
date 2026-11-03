<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A store-wide sale: percent off selected listings between two dates, shown as a strike-through price. */
class SaleEvent extends Model
{
    protected $fillable = ['seller_id', 'name', 'percent_off', 'starts_at', 'ends_at', 'status'];

    protected $casts = ['percent_off' => 'decimal:2', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleEventProduct::class);
    }

    /** Live status from the clock, so the list is right even between maintenance runs. */
    public function liveStatus(): string
    {
        if ($this->status === 'cancelled') {
            return 'cancelled';
        }

        return match (true) {
            $this->ends_at->isPast() => 'ended',
            $this->starts_at->isFuture() => 'scheduled',
            default => 'active',
        };
    }
}
