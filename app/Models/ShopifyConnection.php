<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A seller's linked Shopify store. Tokens are encrypted at rest. */
class ShopifyConnection extends Model
{
    protected $fillable = [
        'seller_id', 'shop_domain', 'shop_name', 'access_token', 'refresh_token', 'token_expires_at',
        'refresh_expires_at', 'scopes', 'status', 'last_error', 'sync_status', 'sync_total', 'sync_done',
        'last_full_sync_at', 'last_event_at',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'refresh_expires_at' => 'datetime',
        'last_full_sync_at' => 'datetime',
        'last_event_at' => 'datetime',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(ShopifyProductLink::class);
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected' && filled($this->access_token);
    }
}
