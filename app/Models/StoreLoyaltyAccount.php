<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreLoyaltyAccount extends Model
{
    protected $fillable = ['user_id', 'seller_id', 'points', 'lifetime_points'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(StoreLoyaltyLedgerEntry::class);
    }

    public function move(int $delta, string $reason, ?int $orderId = null): void
    {
        $this->points = max(0, $this->points + $delta);
        if ($delta > 0) {
            $this->lifetime_points += $delta;
        }
        $this->save();

        $this->ledger()->create([
            'delta' => $delta,
            'balance_after' => $this->points,
            'reason' => $reason,
            'order_id' => $orderId,
        ]);
    }
}
