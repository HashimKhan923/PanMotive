<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** A homepage banner the admin manages. Several in one place play as a slider. */
class Banner extends Model
{
    /** Where on the homepage a banner can go. */
    public const PLACEMENTS = [
        'below_categories' => 'Under "Shop by category"',
        'below_deals' => 'Under "Today\'s deals"',
        'below_listings' => 'Under "New listings"',
    ];

    public const STYLES = [
        'charcoal' => 'Charcoal',
        'red' => 'Performance Red',
        'light' => 'Light',
    ];

    protected $fillable = [
        'eyebrow', 'title', 'subtitle', 'button_label', 'button_url', 'second_label', 'second_url',
        'image_path', 'style', 'placement', 'sort_order', 'is_active', 'starts_at', 'ends_at', 'updated_by',
    ];

    protected $casts = ['is_active' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'sort_order' => 'integer'];

    /** Switched on, and inside its dates if it has any. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    /** @return array<string, \Illuminate\Support\Collection<int, self>> live banners by placement, in order */
    public static function forHome(): array
    {
        return static::live()->orderBy('sort_order')->orderByDesc('id')->get()->groupBy('placement')->all();
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    /** off | scheduled | ended | live, for the admin list. */
    public function state(): string
    {
        return match (true) {
            ! $this->is_active => 'off',
            $this->starts_at && $this->starts_at->isFuture() => 'scheduled',
            $this->ends_at && ! $this->ends_at->isFuture() => 'ended',
            default => 'live',
        };
    }

    public function placementLabel(): string
    {
        return self::PLACEMENTS[$this->placement] ?? $this->placement;
    }
}
