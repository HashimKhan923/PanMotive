<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One channel of a social post. Status: pending → published | failed (auto-posted through the
 * platform API), or share_kit → shared (the seller posts it themselves with the share kit).
 */
class SocialPostTarget extends Model
{
    protected $fillable = [
        'social_post_id', 'social_account_id', 'promo_link_id', 'platform', 'status',
        'external_id', 'permalink', 'error', 'metrics', 'published_at', 'caption',
    ];

    protected $casts = ['metrics' => 'array', 'published_at' => 'datetime'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }

    /** The tracked link used in this channel's post — real clicks, orders and revenue. */
    public function promoLink(): BelongsTo
    {
        return $this->belongsTo(PromoLink::class);
    }

    /** The link that goes in the post: tracked when we have one, else the plain listing URL. */
    public function link(): ?string
    {
        return $this->promoLink ? route('promo.go', $this->promoLink) : $this->post?->link_url;
    }
}
