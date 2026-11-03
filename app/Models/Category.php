<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = [
        'parent_id', 'name', 'slug', 'description', 'image_path',
        'meta_title', 'meta_description', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Root categories that have something to sell, each with listings_count (subcategories included).
     * Shared by the header, home and part finder, so an empty category never gets a link.
     */
    public static function shopRoots(): \Illuminate\Support\Collection
    {
        return \Illuminate\Support\Facades\Cache::remember('shop.category_roots', 300, function () {
            $counts = Product::listed()->selectRaw('category_id, COUNT(*) AS aggregate')
                ->groupBy('category_id')->pluck('aggregate', 'category_id');

            $all = static::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();

            return $all->whereNull('parent_id')
                ->each(fn ($root) => $root->listings_count = (int) ($counts[$root->id] ?? 0)
                    + $all->where('parent_id', $root->id)->sum(fn ($c) => (int) ($counts[$c->id] ?? 0)))
                ->filter(fn ($root) => $root->listings_count > 0)
                ->values();
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(Attribute::class, 'category_attribute')
            ->withPivot(['is_required', 'is_filterable']);
    }
}
