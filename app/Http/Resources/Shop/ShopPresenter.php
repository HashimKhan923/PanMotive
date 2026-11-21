<?php

namespace App\Http\Resources\Shop;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Services\GarageService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * JSON for the shopping app. Prices, sale rules, ratings and "fits your vehicle" are worked out
 * exactly as the website's product card does (see components/product-card.blade.php), so a part
 * never shows one price on the site and another in the app. Brand names are not shown to shoppers.
 */
class ShopPresenter
{
    /** @var array<int, bool> product id => fits the garage vehicle */
    private array $fits = [];

    /** @var array<int, string> product id => "Honda Civic +2 more" */
    private array $vehicles = [];

    /** @var array<int, true> product ids in the shopper's wishlist */
    private array $saved = [];

    private ?int $vehicleId;

    public function __construct(?int $vehicleId = null)
    {
        $this->vehicleId = $vehicleId ?? app(GarageService::class)->vehicleId();
    }

    /** Look up everything the cards need for these products in a few queries instead of a few per card. */
    public function prime(iterable $products): self
    {
        $ids = collect($products)->map(fn ($p) => $p instanceof Product ? $p->id : (int) $p)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return $this;
        }

        if ($this->vehicleId) {
            $fitting = DB::table('product_fitments')->where('vehicle_id', $this->vehicleId)
                ->whereIn('product_id', $ids)->pluck('product_id')->flip();
            $withAny = DB::table('product_fitments')->whereIn('product_id', $ids)->distinct()->pluck('product_id')->flip();
            foreach ($ids as $id) {
                // null = the listing has no vehicles, so we can't say either way
                $this->fits[$id] = isset($fitting[$id]) ? true : (isset($withAny[$id]) ? false : null);
            }
        }

        $pairs = DB::table('product_fitments')
            ->join('vehicles', 'vehicles.id', '=', 'product_fitments.vehicle_id')
            ->join('vehicle_makes', 'vehicle_makes.id', '=', 'vehicles.vehicle_make_id')
            ->leftJoin('vehicle_models', 'vehicle_models.id', '=', 'vehicles.vehicle_model_id')
            ->whereIn('product_fitments.product_id', $ids)
            ->select('product_fitments.product_id', 'vehicle_makes.name as make', 'vehicle_models.name as model')
            ->distinct()->orderBy('vehicle_makes.name')->orderBy('vehicle_models.name')->get()
            ->groupBy('product_id');
        foreach ($pairs as $productId => $rows) {
            $first = trim($rows[0]->make.' '.($rows[0]->model ?? ''));
            $this->vehicles[$productId] = $rows->count() > 1 ? $first.' +'.($rows->count() - 1).' more' : $first;
        }

        if ($user = auth()->user()) {
            $this->saved = DB::table('wishlist_items')
                ->join('wishlists', 'wishlists.id', '=', 'wishlist_items.wishlist_id')
                ->where('wishlists.user_id', $user->id)->whereIn('wishlist_items.product_id', $ids)
                ->pluck('wishlist_items.product_id')->flip()->map(fn () => true)->all();
        }

        return $this;
    }

    /** @return array<string, mixed> */
    public function card(Product $p): array
    {
        $images = $p->relationLoaded('media') ? $p->getMedia('images') : $p->getMedia('images');
        $first = $images->first();
        $sale = $p->liveSalePrice();
        $price = (float) $p->price;
        $hasReviews = (int) ($p->reviews_count ?? 0) > 0;
        $hasVariants = isset($p->active_variants_count) ? $p->active_variants_count > 0 : $p->hasVariants();
        $profile = $p->seller?->sellerProfile;

        return [
            'id' => $p->id,
            'slug' => $p->slug,
            'title' => $p->title,
            'image' => $first?->getUrl('thumb') ?: $p->getFirstMediaUrl('images', 'thumb') ?: null,
            'image_large' => $first?->getUrl('webp') ?: null,
            'image_alt' => $images->get(1)?->getUrl('thumb'),
            'price' => round($price, 2),
            'sale_price' => $sale !== null ? round($sale, 2) : null,
            'effective_price' => round($p->effectivePrice(), 2),
            'off_pct' => $sale ? (int) round((1 - $sale / max($price, 0.01)) * 100) : 0,
            'deal_ends_at' => $sale && $p->deal_ends_at ? $p->deal_ends_at->toIso8601String() : null,
            'basis' => $p->priceBasis(),
            'rating' => $hasReviews ? round((float) $p->avg_rating, 1) : round((float) ($profile?->rating ?? 0), 1),
            'rating_count' => $hasReviews ? (int) $p->reviews_count : null,
            'rating_is_seller' => ! $hasReviews,
            'stock' => (int) $p->quantity,
            'in_stock' => (int) $p->quantity > 0,
            'has_variants' => $hasVariants,
            'quick_add' => ! $hasVariants && (int) $p->quantity > 0 && ! $profile?->salesPaused(),
            'condition' => $p->condition,
            'part_number' => $p->part_number ?: null,
            'fits_vehicle' => $this->vehicles[$p->id] ?? null,
            'fits_garage' => $this->vehicleId ? ($this->fits[$p->id] ?? null) : null,
            'saved' => isset($this->saved[$p->id]),
            'seller' => $profile ? ['name' => $profile->business_name, 'slug' => $profile->slug] : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function cards(iterable $products): array
    {
        $products = collect($products);
        $this->prime($products);

        return $products->map(fn (Product $p) => $this->card($p))->values()->all();
    }

    /** @return array<string, mixed> */
    public function seller(SellerProfile $sp, bool $full = false): array
    {
        $out = [
            'name' => $sp->business_name,
            'slug' => $sp->slug,
            'logo' => $sp->logoUrl(),
            'rating' => round((float) $sp->rating, 1),
            'rating_count' => (int) $sp->rating_count,
            'tier' => $sp->hasBadge() ? $sp->tierLabel() : null,
            'reply_time' => $sp->responseLabel(),
            'away' => $sp->salesPaused(),
        ];

        if ($full) {
            $theme = $sp->theme ?? [];
            $out += [
                'banner' => $sp->banner_path ? Storage::disk('public')->url($sp->banner_path) : null,
                'accent' => $theme['accent'] ?? null,
                'headline' => $theme['hero_headline'] ?? null,
                'subtext' => $theme['hero_subtext'] ?? null,
                'about' => $theme['about'] ?? $sp->bio,
                'sections' => $theme['sections'] ?? [],
                'return_policy' => $sp->return_policy,
                'return_window_days' => (int) ($sp->return_window_days ?? 30),
                'since' => $sp->created_at?->toIso8601String(),
                'vacation_message' => $sp->salesPaused() ? $sp->vacation_message : null,
                'announcement' => $sp->announcement && (! $sp->announcement_until || $sp->announcement_until->isFuture()) ? $sp->announcement : null,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function category(Category $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'slug' => $c->slug,
            'icon' => $c->iconName(),
            'count' => isset($c->listings_count) ? (int) $c->listings_count : null,
            'children' => isset($c->shown_children)
                ? collect($c->shown_children)->map(fn ($k) => ['id' => $k->id, 'name' => $k->name, 'slug' => $k->slug, 'count' => (int) ($k->listings_count ?? 0)])->values()->all()
                : [],
        ];
    }

    /** @return array<string, mixed> */
    public function banner(Banner $b): array
    {
        return [
            'id' => $b->id,
            'placement' => $b->placement,
            'style' => $b->style,
            'eyebrow' => $b->eyebrow,
            'title' => $b->title,
            'subtitle' => $b->subtitle,
            'image' => $b->imageUrl(),
            'button' => $b->button_url ? ['label' => $b->button_label, 'link' => AppLink::from($b->button_url)] : null,
            'second' => $b->second_url ? ['label' => $b->second_label, 'link' => AppLink::from($b->second_url)] : null,
        ];
    }

    /** Pagination block the app's infinite lists read. */
    public static function page(\Illuminate\Contracts\Pagination\LengthAwarePaginator $p): array
    {
        return ['page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'per_page' => $p->perPage(), 'total' => $p->total()];
    }

    public static function money(float|int|string|null $v): float
    {
        return round((float) $v, 2);
    }

    /** Product ids with their listed vehicle label, for one product outside a list. */
    public function vehicleLabelFor(Product $p): ?string
    {
        $this->prime([$p]);

        return $this->vehicles[$p->id] ?? null;
    }

    public function fitsGarage(Product $p): ?bool
    {
        $this->prime([$p]);

        return $this->vehicleId ? ($this->fits[$p->id] ?? null) : null;
    }

    public function vehicleId(): ?int
    {
        return $this->vehicleId;
    }

    /** @param Collection<int, Product> $products */
    public static function forProducts(Collection $products): self
    {
        return (new self)->prime($products);
    }
}
