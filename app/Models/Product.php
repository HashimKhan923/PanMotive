<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Product extends Model implements HasMedia
{
    use InteractsWithMedia, Searchable, SoftDeletes;

    protected $fillable = [
        'seller_id', 'category_id', 'brand_id', 'title', 'slug', 'sku',
        'part_number', 'oem_number', 'description', 'bullet_points',
        'price', 'sale_price', 'cost_price', 'quantity', 'weight_lbs',
        'dimensions', 'condition', 'status', 'ai_generated_content',
        'meta_title', 'meta_description', 'structured_data', 'published_at',
    ];

    protected $casts = [
        'bullet_points' => 'array',
        'dimensions' => 'array',
        'structured_data' => 'array',
        'ai_generated_content' => 'boolean',
        'price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'published_at' => 'datetime',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function fitments(): BelongsToMany
    {
        return $this->belongsToMany(Vehicle::class, 'product_fitments')
            ->withPivot(['position', 'notes'])
            ->withTimestamps();
    }

    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(AttributeValue::class, 'product_attribute_value');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function fitsVehicle(int $vehicleId): bool
    {
        return $this->fitments()->where('vehicles.id', $vehicleId)->exists();
    }

    /** Laravel Scout: what gets pushed to Meilisearch */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'part_number' => $this->part_number,
            'oem_number' => $this->oem_number,
            'category' => $this->category?->name,
            'brand' => $this->brand?->name,
            'price' => (float) ($this->sale_price ?? $this->price),
            'condition' => $this->condition,
            'fitment_vehicle_ids' => $this->fitments()->pluck('vehicles.id')->toArray(),
            'status' => $this->status,
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return $this->status === 'active';
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')->useFallbackUrl('/images/product-placeholder.png');
    }
}
