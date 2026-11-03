<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id', 'label', 'options', 'sku', 'price', 'sale_price',
        'quantity', 'weight_lbs', 'is_active', 'sort_order', 'media_id',
    ];

    protected $casts = [
        'options' => 'array',
        'price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'weight_lbs' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        // A product with options is stocked per option. Keep its headline quantity equal to
        // the sum of its active options so every stock check, filter and alert that reads
        // products.quantity stays correct without having to know about variants.
        $sync = fn (ProductVariant $v) => $v->product?->syncStockFromVariants();
        static::saved($sync);
        static::deleted($sync);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /** This variant's own photo if it has one, else the product's primary gallery image. */
    public function imageUrl(string $conversion = 'thumb'): ?string
    {
        $media = $this->media ?? $this->product?->getFirstMedia('images');

        return $media?->getUrl($conversion) ?: $media?->getUrl();
    }

    /** Effective list price — falls back to the parent product's price. */
    public function listPrice(): float
    {
        return (float) ($this->price ?? $this->product->price);
    }

    public function salePrice(): ?float
    {
        return $this->sale_price !== null ? (float) $this->sale_price : null;
    }

    public function effectivePrice(): float
    {
        $sale = $this->salePrice();

        return $sale !== null && $sale < $this->listPrice() ? $sale : $this->listPrice();
    }

    public function inStock(): bool
    {
        return $this->quantity > 0;
    }
}
