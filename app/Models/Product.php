<?php

namespace App\Models;

use App\Jobs\GenerateProductSeo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Product extends Model implements HasMedia
{
    use InteractsWithMedia, Searchable, SoftDeletes;

    protected static function booted(): void
    {
        // A listing staff took down can't simply be switched back on by its seller. Publishing it
        // again sends it to staff for review; only their approval (which clears the note) makes it live.
        static::saving(function (Product $product) {
            if ($product->isDirty('status') && $product->status === 'active'
                && filled($product->moderation_note) && ! $product->isDirty('moderation_note')) {
                $product->status = 'pending_review';
            }
        });

        // Queued SEO regeneration (never synchronous). Skipped entirely when no
        // AI key is configured so it isn't a no-op job on every save.
        if (! config('services.anthropic.key')) {
            return;
        }

        static::created(function (Product $product) {
            GenerateProductSeo::dispatch($product->id);
        });

        static::updated(function (Product $product) {
            if ($product->wasChanged(['title', 'description', 'category_id'])) {
                GenerateProductSeo::dispatch($product->id);
            }
        });
    }

    protected $fillable = [
        'seller_id', 'category_id', 'brand_id', 'title', 'slug', 'sku',
        'part_number', 'oem_number', 'description', 'bullet_points',
        'price', 'sale_price', 'accepts_offers', 'min_offer_price', 'auto_accept_offer_price', 'cost_price', 'subscription_enabled', 'subscription_discount_pct',
        'quantity', 'reorder_point', 'reorder_qty', 'weight_lbs', 'shipping_policy_id', 'handling_days',
        'dimensions', 'condition', 'status', 'ai_generated_content',
        'meta_title', 'meta_description', 'structured_data', 'published_at',
        'deal_ends_at', 'sale_starts_at', 'avg_rating', 'reviews_count',
    ];

    protected $casts = [
        'bullet_points' => 'array',
        'dimensions' => 'array',
        'structured_data' => 'array',
        'ai_generated_content' => 'boolean',
        'accepts_offers' => 'boolean',
        'subscription_enabled' => 'boolean',
        'subscription_discount_pct' => 'decimal:2',
        'price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'min_offer_price' => 'decimal:2',
        'auto_accept_offer_price' => 'decimal:2',
        'published_at' => 'datetime',
        'deal_ends_at' => 'datetime',
        'sale_starts_at' => 'datetime',
        'avg_rating' => 'decimal:2',
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

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order')->orderBy('id');
    }

    public function boosts(): HasMany
    {
        return $this->hasMany(Boost::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function repricingRule(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(RepricingRule::class);
    }

    public function shippingPolicy(): BelongsTo
    {
        return $this->belongsTo(ShippingPolicy::class);
    }

    public function stock(): HasMany
    {
        return $this->hasMany(ProductStock::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->latest();
    }

    public function isLowStock(): bool
    {
        return $this->reorder_point !== null && (int) $this->quantity <= (int) $this->reorder_point;
    }

    /** Business days before this item ships: per-product override → its policy → 2. */
    public function effectiveHandlingDays(): int
    {
        return (int) ($this->handling_days
            ?? $this->shippingPolicy?->handling_days
            ?? $this->seller?->sellerProfile?->handlingDays()
            ?? 2);
    }

    public function volumeDiscounts(): HasMany
    {
        return $this->hasMany(VolumeDiscount::class)->orderBy('min_quantity');
    }

    public function bundles(): BelongsToMany
    {
        return $this->belongsToMany(Bundle::class, 'bundle_items')->withPivot('quantity');
    }

    /** Best unit price for a given quantity, applying volume-discount tiers. */
    public function unitPriceForQuantity(int $qty): float
    {
        $base = $this->effectivePrice();
        $tier = $this->relationLoaded('volumeDiscounts')
            ? $this->volumeDiscounts->where('min_quantity', '<=', $qty)->sortByDesc('min_quantity')->first()
            : $this->volumeDiscounts()->where('min_quantity', '<=', $qty)->orderByDesc('min_quantity')->first();

        return $tier ? $tier->unitPrice($base) : $base;
    }

    public function activeBoost(): ?Boost
    {
        return $this->relationLoaded('boosts')
            ? $this->boosts->first(fn ($b) => $b->status === 'active' && $b->ends_at?->isFuture())
            : $this->boosts()->live()->latest()->first();
    }

    public function scopeBoosted($query, ?string $placement = null)
    {
        return $query->whereHas('boosts', fn ($b) => $b->live()
            ->when($placement, fn ($q) => $q->where('placement', $placement)));
    }

    public function activeVariants(): HasMany
    {
        return $this->variants()->where('is_active', true);
    }

    public function hasVariants(): bool
    {
        return $this->activeVariants()->exists();
    }

    /** Keep products.quantity = sum of active variant stock, for products sold by option. */
    public function syncStockFromVariants(): void
    {
        $active = $this->activeVariants();
        if (! $active->exists()) {
            return; // simple product — its own quantity is the truth
        }

        $total = (int) $active->sum('quantity');
        if ((int) $this->quantity !== $total) {
            $this->forceFill(['quantity' => $total])->saveQuietly();
        }
    }

    /** Total sellable stock — sums active variants when the product has them. */
    public function sellableQuantity(): int
    {
        return $this->hasVariants()
            ? (int) $this->activeVariants()->sum('quantity')
            : (int) $this->quantity;
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function publishedReviews(): HasMany
    {
        return $this->hasMany(Review::class)->where('status', 'published')->latest();
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->where('status', 'published')->latest();
    }

    /** What shoppers may see and buy: active listings, minus those of suspended, rejected or banned sellers. */
    public function scopeListed($query)
    {
        return $query->where('products.status', 'active')
            ->whereNotIn('products.seller_id', SellerProfile::whereIn('status', ['suspended', 'rejected'])->select('user_id'))
            ->whereNotIn('products.seller_id', User::where('is_active', false)->select('id'));
    }

    /** scopeListed() for one product already in memory, such as a line in someone's cart. */
    public function isListed(): bool
    {
        return $this->status === 'active'
            && $this->seller?->is_active !== false
            && ! in_array($this->seller?->sellerProfile?->status, ['suspended', 'rejected'], true);
    }

    /** Sort by the price shoppers actually pay, so a sale that has ended or hasn't started doesn't count. */
    public function scopeOrderByLivePrice($query, string $direction = 'asc')
    {
        $direction = $direction === 'desc' ? 'DESC' : 'ASC';
        $now = now()->toDateTimeString();

        return $query->orderByRaw(
            "CASE WHEN products.sale_price IS NOT NULL AND products.sale_price < products.price
                    AND (products.sale_starts_at IS NULL OR products.sale_starts_at <= ?)
                    AND (products.deal_ends_at IS NULL OR products.deal_ends_at > ?)
                  THEN products.sale_price ELSE products.price END {$direction}",
            [$now, $now]
        );
    }

    /**
     * Parts for a make (and optionally model). Many imported listings have no fitment rows and only
     * name the car in the title, so the title counts here. "Fits my exact vehicle" stays fitment-only.
     */
    public function scopeForVehicleMake($query, VehicleMake $make, ?VehicleModel $model = null)
    {
        return $query->where(function ($w) use ($make, $model) {
            $w->whereHas('fitments', fn ($f) => $f->where('vehicles.vehicle_make_id', $make->id)
                ->when($model, fn ($m) => $m->where('vehicles.vehicle_model_id', $model->id)));

            $w->orWhere('products.title', 'like', '%'.($model ?? $make)->name.'%');
        });
    }

    public function scopeOnDeal($query)
    {
        return $query->whereNotNull('sale_price')
            ->whereColumn('sale_price', '<', 'price')
            ->where(fn ($q) => $q->whereNull('sale_starts_at')->orWhere('sale_starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('deal_ends_at')->orWhere('deal_ends_at', '>', now()));
    }

    public function isOnDeal(): bool
    {
        return $this->sale_price && $this->sale_price < $this->price
            && (! $this->sale_starts_at || $this->sale_starts_at->isPast())
            && (! $this->deal_ends_at || $this->deal_ends_at->isFuture());
    }

    /** The sale price only while the deal is actually live — what every storefront price should show. */
    public function liveSalePrice(): ?float
    {
        return $this->isOnDeal() ? (float) $this->sale_price : null;
    }

    /** The store-wide sale (Promotions → Sales) this listing is in right now, if any — for its name and countdown. */
    public function activeSaleEvent(): ?SaleEvent
    {
        if (! $this->isOnDeal()) {
            return null;
        }

        return SaleEvent::whereIn('status', ['scheduled', 'active'])
            ->where('starts_at', '<=', now())->where('ends_at', '>', now())
            ->whereHas('items', fn ($q) => $q->where('product_id', $this->id))
            ->first();
    }

    public function effectivePrice(): float
    {
        return (float) ($this->isOnDeal() ? $this->sale_price : $this->price);
    }

    public function recalculateRatings(): void
    {
        $agg = $this->reviews()->where('status', 'published')
            ->selectRaw('COUNT(*) c, COALESCE(AVG(rating), 0) a')->first();

        $this->forceFill([
            'reviews_count' => (int) $agg->c,
            'avg_rating' => round((float) $agg->a, 2),
        ])->saveQuietly();

        // The store's rating follows, so removing a review also corrects what shoppers see on the store.
        $store = Review::where('status', 'published')->whereHas('product', fn ($q) => $q->where('seller_id', $this->seller_id))
            ->selectRaw('COUNT(*) c, COALESCE(AVG(rating), 0) a')->first();
        $this->seller?->sellerProfile?->forceFill(['rating' => round((float) $store->a, 2), 'rating_count' => (int) $store->c])->saveQuietly();
    }

    public function fitsVehicle(int $vehicleId): bool
    {
        return $this->fitments()->where('vehicles.id', $vehicleId)->exists();
    }

    /**
     * Listing completeness — drives the quality checklist in the seller portal.
     *
     * @return array{score:int, checks:array<int, array{label:string, ok:bool, weight:int}>}
     */
    public function qualityScore(): array
    {
        $photoCount = $this->relationLoaded('media') ? $this->media->count() : $this->media()->count();
        $bullets = is_array($this->bullet_points) ? count($this->bullet_points) : 0;
        $fitCount = $this->relationLoaded('fitments') ? $this->fitments->count() : $this->fitments()->count();
        $descLen = strlen(strip_tags((string) $this->description));
        $titleLen = strlen((string) $this->title);

        $checks = [
            ['label' => '3+ photos', 'ok' => $photoCount >= 3, 'weight' => 20],
            ['label' => 'Title 40–140 characters', 'ok' => $titleLen >= 40 && $titleLen <= 140, 'weight' => 12],
            ['label' => 'Description 120+ characters', 'ok' => $descLen >= 120, 'weight' => 16],
            ['label' => '3+ highlight bullets', 'ok' => $bullets >= 3, 'weight' => 12],
            ['label' => 'Vehicle fitment added', 'ok' => $fitCount >= 1, 'weight' => 20],
            ['label' => 'Brand selected', 'ok' => ! is_null($this->brand_id), 'weight' => 5],
            ['label' => 'Part number', 'ok' => filled($this->part_number), 'weight' => 8],
            ['label' => 'Shipping weight', 'ok' => ! is_null($this->weight_lbs), 'weight' => 7],
        ];

        $score = collect($checks)->where('ok', true)->sum('weight');

        return ['score' => (int) $score, 'checks' => $checks];
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
            'category_id' => $this->category_id,
            'brand' => $this->brand?->name,
            'brand_id' => $this->brand_id,
            'seller_id' => $this->seller_id,
            'price' => (float) ($this->sale_price ?? $this->price),
            'condition' => $this->condition,
            'fitment_vehicle_ids' => $this->fitments()->pluck('vehicles.id')->toArray(),
            'status' => $this->status,
            'created_at' => $this->created_at?->timestamp,
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return $this->status === 'active';
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')
            ->useFallbackUrl('/images/product-placeholder.svg')
            ->useFallbackUrl('/images/product-placeholder.svg', 'thumb')
            ->useFallbackUrl('/images/product-placeholder.svg', 'webp');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('webp')
            ->format('webp')
            ->fit(Fit::Crop, 800, 800)
            ->nonQueued();

        // Generated synchronously so thumbnails appear immediately after upload
        // even without a queue worker running.
        $this->addMediaConversion('thumb')
            ->format('webp')
            ->fit(Fit::Crop, 300, 300)
            ->performOnCollections('images')
            ->nonQueued();
    }
}
