<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SellerTeamMember extends Model
{
    protected $fillable = [
        'owner_id', 'user_id', 'email', 'name', 'role', 'abilities', 'status',
        'invite_token', 'invited_at', 'accepted_at', 'last_active_at',
    ];

    protected $casts = [
        'abilities' => 'array',
        'invited_at' => 'datetime',
        'accepted_at' => 'datetime',
        'last_active_at' => 'datetime',
    ];

    /** Every ability the seller portal recognises. */
    public const ABILITIES = [
        'orders.view' => 'View orders',
        'orders.manage' => 'Accept, ship & refund orders',
        'products.view' => 'View listings',
        'products.manage' => 'Create & edit listings',
        'inventory.manage' => 'Adjust stock & locations',
        'fulfilment' => 'Pick lists, packing slips & labels',
        'returns.manage' => 'Handle returns & claims',
        'messages' => 'Reply to buyer messages',
        'marketing' => 'Campaigns, ads, social & promotions',
        'payouts.view' => 'View payouts',
        'finances.view' => 'View ledger, statements & reports',
        'settings.manage' => 'Change store settings',
        'team.manage' => 'Invite & manage team members',
    ];

    /** Role presets. "manager" is everything except team.manage; owner keeps that. */
    public const ROLE_PRESETS = [
        'manager' => [
            'orders.view', 'orders.manage', 'products.view', 'products.manage', 'inventory.manage',
            'fulfilment', 'returns.manage', 'messages', 'marketing', 'payouts.view', 'finances.view', 'settings.manage',
        ],
        'staff' => [
            'orders.view', 'orders.manage', 'products.view', 'products.manage', 'inventory.manage', 'fulfilment', 'messages',
        ],
        'viewer' => [
            'orders.view', 'products.view', 'payouts.view', 'finances.view',
        ],
    ];

    protected static function booted(): void
    {
        static::creating(function (SellerTeamMember $m) {
            $m->invite_token ??= Str::random(40);
            $m->invited_at ??= now();
            if (empty($m->abilities)) {
                $m->abilities = self::presetAbilities($m->role);
            }
        });
    }

    public static function presetAbilities(string $role): array
    {
        return self::ROLE_PRESETS[$role] ?? self::ROLE_PRESETS['staff'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->user_id !== null;
    }

    public function can(string $ability): bool
    {
        return in_array($ability, $this->abilities ?? [], true);
    }
}
