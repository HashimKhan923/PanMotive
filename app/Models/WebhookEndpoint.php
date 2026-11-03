<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookEndpoint extends Model
{
    protected $fillable = [
        'seller_id', 'url', 'secret', 'events', 'is_active',
        'failure_count', 'last_status', 'last_delivered_at',
    ];

    protected $casts = ['events' => 'array', 'is_active' => 'boolean', 'last_delivered_at' => 'datetime'];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function listensFor(string $event): bool
    {
        return $this->is_active && in_array($event, $this->events ?? [], true);
    }

    public function deliveries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WebhookDelivery::class)->latest('id');
    }
}
