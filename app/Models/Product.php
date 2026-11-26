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
    use InteractsWithMedia {
        getMedia as protected ownMedia;
    }
    use Searchable, SoftDeletes;

    /**
     * Fields a Supply Network listing copies from the supplier's product and can't change itself.
     * The reseller only controls whether its listing is live (status).
     */
    public const SUPPLY_SYNCED = [
        'category_id', 'brand_id', 'title', 'sku', 'part_number', 'oem_number', 'description', 'bullet_points',
        'price', 'sale_price', 'sale_starts_at', 'deal_ends_at', 'quantity', 'weight_lbs', 'handling_days',
        'dimensions', 'condition', 'meta_title', 'meta_description', 'structured_data', 'avg_rating', 'reviews_count',
    ];

    /** True while SupplyNetworkService writes a copy, so the guard below lets the change through. */
    public static bool $supplySyncing = false;

    protected static function booted(): void
    {
        // A Supply Network listing mirrors its supplier's product: price, stock, title, photos and
        // fitment come from the supplier. Anything else that tries to change them (bulk edits, sale
        // events, repricing...) is undone here, so the supplier is always paid what they set.
        static::saving(function (Product $product) {
            if ($product->source_product_id && ! static::$supplySyncing && $product->exists) {
                foreach (self::SUPPLY_SYNCED as $field) {
                    if ($product->isDirty($field)) {
                        $product->{$field} = $product->getOriginal($field);
                    }
                }
                $product->accepts_offers = false;
                $product->subscription_enabled = false;
            }
        });

        // A supplier's change reaches every reseller copy: price and stock straight away,
        // the rest (options, fitment) in the background.
        static::saved(function (Product $product) {
            if (! $product->source_product_id && $product->resoldCopies()->exists()) {
                app(\App\Services\Seller\SupplyNetworkService::class)->pushQuick($product);
                \App\Jobs\SyncSupplyListings::dispatch($product->id)->afterCommit();
            }
        });

        // A listing staff took down can't simply be switched back on by its seller. Publishing it
        // again sends it to staff for review; only their approval (which clears the note) makes it live.
        static::saving(function (Product $product) {
            if ($product->isDirty('status') && $product->status === 'active'
                && filled($product->moderation_note) && ! $product->isDirty('moderation_note')) {
                $product->status = 'pending_review';
            }
        });

        // A deleted listing's photos are removed from storage (R2) too. A force delete is handled
        // by the media library itself; a normal (soft) delete would otherwise keep every file.
        static::deleted(function (Product $product) {
            if (! $product->isForceDeleting()) {
                \App\Jobs\DeleteProductPhotos::dispatch($product->id)->afterCommit();
            }
        });

        // Back-in-stock emails for shoppers who asked to be told.
        static::updated(function (Product $product) {
            if ($product->wasChanged('quantity')
                && \App\Services\Marketing\StockAlertService::cameBack($product->getOriginal('quantity'), $product->quantity)) {
                \Illuminate\Support\Facades\DB::afterCommit(fn () => app(\App\Services\Marketing\StockAlertService::class)->restocked($product));
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
        'deal_ends_at', 'sale_starts_at', 'avg_rating', 'reviews_count', 'source_product_id', 'supply_excluded',
    ];

    protected $casts = [
        'bullet_points' => 'array',
        'dimensions' => 'array',
        'structured_data' => 'array',
        'ai_generated_content' => 'boolean',
        'accepts_offers' => 'boolean',
        'supply_excluded' => 'boolean',
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

    /** For a Supply Network listing: the supplier's product it resells. */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'source_product_id');
    }

    /** Resellers' listings of this product. */
    public function resoldCopies(): HasMany
    {
        return $this->hasMany(Product::class, 'source_product_id');
    }

    public function isResold(): bool
    {
        return (bool) $this->source_product_id;
    }

    /** The product that's physically shipped: the supplier's for a resold listing, else this one. */
    public function shippedProduct(): Product
    {
        return $this->source_product_id && $this->source ? $this->source : $this;
    }

    /** A resold listing shows the supplier's photos - always the same, nothing copied. */
    public function getMedia(string $collectionName = 'default', array|callable $filters = []): \Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection
    {
        if ($this->source_product_id && ($source = $this->source)) {
            return $source->getMedia($collectionName, $filters);
        }

        return $this->ownMedia($collectionName, $filters);
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
            $before = (int) $this->quantity;
            $this->forceFill(['quantity' => $total])->saveQuietly();

            if (\App\Services\Marketing\StockAlertService::cameBack($before, $total)) {
                \Illuminate\Support\Facades\DB::afterCommit(fn () => app(\App\Services\Marketing\StockAlertService::class)->restocked($this));
            }
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
            ->whereNotIn('products.seller_id', User::where('is_active', false)->select('id'))
            // A resold listing is only buyable while its supplier's product is, the supplier still
            // offers it, and the two stores are still connected.
            ->where(fn ($q) => $q->whereNull('products.source_product_id')->orWhereExists(fn ($s) => $s->selectRaw('1')
                ->from('products as src')
                ->join('seller_profiles as src_sp', 'src_sp.user_id', '=', 'src.seller_id')
                ->join('supply_connections as sc', fn ($j) => $j->on('sc.supplier_id', '=', 'src.seller_id')->on('sc.reseller_id', '=', 'products.seller_id'))
                ->whereColumn('src.id', 'products.source_product_id')
                ->where('src.status', 'active')->whereNull('src.deleted_at')->where('src.supply_excluded', false)
                ->where('src_sp.supply_enabled', true)->whereNotIn('src_sp.status', ['suspended', 'rejected'])
                ->where('sc.status', 'approved')
                ->whereNotIn('src.seller_id', User::where('is_active', false)->select('id'))));
    }

    /** Only sellers' own listings (not resold copies): search and browse lists show each part once. */
    public function scopeOriginal($query)
    {
        return $query->whereNull('products.source_product_id');
    }

    /** scopeListed() for one product already in memory, such as a line in someone's cart. */
    public function isListed(): bool
    {
        $own = $this->status === 'active'
            && $this->seller?->is_active !== false
            && ! in_array($this->seller?->sellerProfile?->status, ['suspended', 'rejected'], true);
        if (! $own || ! $this->source_product_id) {
            return $own;
        }
        $source = $this->source;

        return $source !== null && $source->isListed() && ! $source->supply_excluded
            && (bool) $source->seller?->sellerProfile?->supply_enabled
            && SupplyConnection::where('supplier_id', $source->seller_id)->where('reseller_id', $this->seller_id)
                ->where('status', 'approved')->exists();
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
        // Resold copies stay out of search: the supplier's own listing already represents the part.
        return $this->status === 'active' && ! $this->source_product_id;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')
            ->useFallbackUrl('/images/product-placeholder.svg')
            ->useFallbackUrl('/images/product-placeholder.svg', 'thumb')
            ->useFallbackUrl('/images/product-placeholder.svg', 'webp');
    }

    /** The word shown after a price: each, pair or set. Read from the title, because listings have no field for it. */
    public function priceBasis(): string
    {
        return match (true) {
            (bool) preg_match('/\b(pair|pairs)\b/i', $this->title) => 'pair',
            (bool) preg_match('/\b(set|sets|kit|kits|\d+-pack|pack of \d+)\b/i', $this->title) => 'set',
            default => 'each',
        };
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

    /** "Honda Civic" / "Honda Civic +2 more" from the listed fitment — shown to shoppers instead of the brand. */
    public function vehicleLabel(): ?string
    {
        if (! array_key_exists('vehicle_label', $this->attributes ?? []) && ! isset($this->vehicleLabelCache)) {
            $pairs = \Illuminate\Support\Facades\DB::table('product_fitments')
                ->join('vehicles', 'vehicles.id', '=', 'product_fitments.vehicle_id')
                ->join('vehicle_makes', 'vehicle_makes.id', '=', 'vehicles.vehicle_make_id')
                ->leftJoin('vehicle_models', 'vehicle_models.id', '=', 'vehicles.vehicle_model_id')
                ->where('product_fitments.product_id', $this->id)
                ->select('vehicle_makes.name as make', 'vehicle_models.name as model')
                ->distinct()->orderBy('vehicle_makes.name')->orderBy('vehicle_models.name')
                ->limit(50)->get();
            if ($pairs->isEmpty()) {
                $this->vehicleLabelCache = '';
            } else {
                $first = trim($pairs[0]->make.' '.($pairs[0]->model ?? ''));
                $more = $pairs->count() - 1;
                $this->vehicleLabelCache = $more > 0 ? "{$first} +{$more} more" : $first;
            }
        }

        return ($this->vehicleLabelCache ?? '') !== '' ? $this->vehicleLabelCache : null;
    }

    /** @var string|null per-instance cache for vehicleLabel() */
    public ?string $vehicleLabelCache = null;
}
