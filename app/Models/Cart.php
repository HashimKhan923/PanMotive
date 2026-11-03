<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    protected $fillable = ['user_id', 'session_id', 'abandoned_reminder_sent_at'];

    protected $casts = ['abandoned_reminder_sent_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function subtotal(): float
    {
        return (float) $this->items
            ->filter(fn ($i) => $i->product && $i->product->isListed())
            ->sum(fn ($i) => $i->product->effectivePrice() * $i->quantity);
    }
}
