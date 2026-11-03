<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialPost extends Model
{
    protected $fillable = [
        'seller_id', 'product_id', 'body', 'link_url', 'media_path',
        'status', 'scheduled_at', 'published_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(SocialPostTarget::class);
    }

    public function recomputeStatus(): void
    {
        $t = $this->targets()->pluck('status');
        $status = match (true) {
            $t->isEmpty() => 'draft',
            $t->every(fn ($s) => $s === 'published') => 'published',
            $t->contains('published') => 'partial',
            $t->every(fn ($s) => $s === 'failed') => 'failed',
            // Waiting on the seller to post it themselves from the share kit.
            $t->contains(fn ($s) => in_array($s, ['share_kit', 'shared'], true)) => 'ready',
            default => $this->status,
        };
        $this->forceFill([
            'status' => $status,
            'published_at' => in_array($status, ['published', 'partial'], true) ? ($this->published_at ?? now()) : $this->published_at,
        ])->save();
    }
}
