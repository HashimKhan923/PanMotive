<?php

namespace App\Http\Controllers\Api\Shop;

use App\Http\Controllers\Controller;
use App\Http\Resources\Shop\ShopPresenter;
use App\Models\Banner;
use App\Models\Bundle;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductView;
use App\Models\Subscription;
use App\Services\GarageService;
use App\Services\Recommendations\RecommendationService;
use App\Services\Search\CatalogSearch;
use App\Services\Search\ShopQuery;
use App\Services\Shipping\ShippingEstimateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Browsing in the shopping app: home, categories, search + filters, product pages, deals. */
class CatalogController extends Controller
{
    /** Everything the home screen shows, in one call. */
    public function home(Request $request, GarageService $garage): JsonResponse
    {
        $present = new ShopPresenter;
        $vehicle = $garage->current();

        $featured = Product::listed()->with('seller.sellerProfile', 'media')->withCount('activeVariants')->latest()->take(8)->get();
        $deals = Product::onDeal()->listed()->with('seller.sellerProfile', 'media')->withCount('activeVariants')
            ->orderByRaw('(price - sale_price) / price DESC')->take(8)->get();
        $forVehicle = $vehicle
            ? Product::listed()->whereHas('fitments', fn ($q) => $q->where('vehicles.id', $vehicle['id']))
                ->with('seller.sellerProfile', 'media')->withCount('activeVariants')->latest()->take(8)->get()
            : collect();
        $fromFollowed = $request->user()
            ? Product::listed()->whereIn('seller_id', $request->user()->followedSellers()->pluck('users.id'))
                ->with('seller.sellerProfile', 'media')->withCount('activeVariants')->latest()->take(8)->get()
            : collect();

        $present->prime($featured->concat($deals)->concat($forVehicle)->concat($fromFollowed));

        $banners = Banner::forHome();

        return response()->json([
            'hero' => [
                'badge' => config('marketplace.platform.hero_badge'),
                'line_1' => config('marketplace.platform.hero_line_1'),
                'line_2' => config('marketplace.platform.hero_line_2'),
                'accent' => config('marketplace.platform.hero_accent'),
                'text' => config('marketplace.platform.hero_text'),
                'points' => array_values(array_filter([
                    config('marketplace.platform.hero_point_1'), config('marketplace.platform.hero_point_2'), config('marketplace.platform.hero_point_3'),
                ])),
            ],
            'announcement' => $this->announcement(),
            'garage' => $vehicle,
            'categories' => Category::shopRoots()->take(12)->map(fn ($c) => $present->category($c))->values(),
            'banners' => collect($banners)->map(fn ($group) => collect($group)->map(fn ($b) => $present->banner($b))->values()),
            'deals' => $deals->map(fn ($p) => $present->card($p))->values(),
            'for_vehicle' => $forVehicle->map(fn ($p) => $present->card($p))->values(),
            'from_followed' => $fromFollowed->map(fn ($p) => $present->card($p))->values(),
            'new' => $featured->map(fn ($p) => $present->card($p))->values(),
        ]);
    }

    public function categories(): JsonResponse
    {
        $present = new ShopPresenter;
        $all = Category::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'parent_id', 'name', 'slug']);
        $roots = Category::shopRoots();

        return response()->json([
            'categories' => $roots->map(function ($root) use ($all, $present) {
                $row = $present->category($root);
                $row['children'] = $all->where('parent_id', $root->id)->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug])->values();

                return $row;
            })->values(),
        ]);
    }

    /** The shop / search results — the same search and filters as the website's shop page. */
    public function products(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'nullable|string|max:120', 'category' => 'nullable|string|max:120', 'condition' => 'nullable|in:new,used,refurbished',
            'make' => 'nullable|string|max:80', 'model' => 'nullable|string|max:80', 'vehicleId' => 'nullable|integer',
            'sort' => 'nullable|in:,relevance,newest,price_asc,price_desc', 'page' => 'nullable|integer|min:1|max:500',
            'per_page' => 'nullable|integer|min:6|max:48', 'facets' => 'nullable|boolean',
        ]);

        $search = ShopQuery::make($request->only(['q', 'category', 'condition', 'make', 'model', 'vehicleId', 'sort']));
        $results = $search->results((int) $request->input('per_page', 24), (int) $request->input('page', 1));
        $results->getCollection()->loadCount('activeVariants');

        $present = new ShopPresenter;
        $out = [
            'products' => $present->cards($results->getCollection()),
            'meta' => ShopPresenter::page($results),
            'notice' => $search->fallbackNotice ?: null,
        ];

        // Filter counts only on the first page (the filter sheet needs them once).
        if ($request->boolean('facets', true) && $results->currentPage() === 1) {
            $out['facets'] = [
                'categories' => $search->categoryTree()->map(fn ($c) => $present->category($c))->values(),
                'conditions' => $search->conditionCounts(),
                'selected_category' => $search->selectedCategory()?->only(['name', 'slug', 'parent_id']),
            ];
        }

        return response()->json($out);
    }

    public function product(Request $request, string $slug, ShippingEstimateService $shipping): JsonResponse
    {
        $product = Product::query()->where('slug', $slug)->listed()
            ->with([
                'category.parent', 'seller.sellerProfile', 'media',
                'fitments.make', 'fitments.model', 'fitments.engine',
                'attributeValues.attribute', 'volumeDiscounts', 'activeVariants',
            ])->withCount('activeVariants')->firstOrFail();

        $product->increment('views_count');
        ProductView::create([
            'product_id' => $product->id,
            'viewer_key' => $request->user()
                ? 'u'.$request->user()->id
                : 'a'.substr((string) (app()->bound('shop.device') ? app('shop.device') : $request->ip()), 0, 40),
            'viewed_at' => now(),
        ]);

        $present = new ShopPresenter;
        $card = $present->prime([$product])->card($product);
        $sp = $product->seller?->sellerProfile;
        $handling = $product->effectiveHandlingDays();
        $policy = $shipping->policyForProduct($product);
        $variants = $product->activeVariants;

        $bundles = Bundle::where('is_active', true)
            ->whereHas('items', fn ($q) => $q->where('product_id', $product->id))
            ->with('products.media', 'products.seller.sellerProfile')->get()
            ->filter(fn ($b) => $b->isAvailable());

        $reviewCounts = $product->reviews()->where('status', 'published')
            ->selectRaw('rating, COUNT(*) as c')->groupBy('rating')->pluck('c', 'rating');
        $saleEvent = $product->activeSaleEvent();

        return response()->json(['product' => $card + [
            'description_html' => $product->description ?: null,
            'oem_number' => $product->oem_number ?: null,
            'category' => $product->category ? [
                'name' => $product->category->name, 'slug' => $product->category->slug,
                'parent' => $product->category->parent ? ['name' => $product->category->parent->name, 'slug' => $product->category->parent->slug] : null,
            ] : null,
            'gallery' => $product->getMedia('images')->map(fn ($m) => [
                'id' => $m->id, 'thumb' => $m->getUrl('thumb'), 'large' => $m->getUrl('webp') ?: $m->getUrl(),
            ])->values(),
            'price_range' => $variants->isNotEmpty() ? [
                'min' => round($variants->min(fn ($v) => $v->effectivePrice()), 2),
                'max' => round($variants->max(fn ($v) => $v->effectivePrice()), 2),
            ] : null,
            'variants' => $variants->map(fn ($v) => [
                'id' => $v->id, 'label' => $v->label, 'options' => $v->options ?? [], 'sku' => $v->sku,
                'price' => round($v->listPrice(), 2), 'sale_price' => $v->salePrice() !== null ? round($v->salePrice(), 2) : null,
                'effective_price' => round($v->effectivePrice(), 2), 'stock' => (int) $v->quantity, 'in_stock' => $v->inStock(),
                'image' => $v->imageUrl('webp'), 'media_id' => $v->media_id,
            ])->values(),
            'stock' => $variants->isNotEmpty() ? (int) $variants->sum('quantity') : (int) $product->quantity,
            'deal' => $product->isOnDeal() && $product->deal_ends_at ? [
                'name' => $saleEvent?->name ?? 'Limited-time deal', 'ends_at' => $product->deal_ends_at->toIso8601String(),
            ] : null,
            'shipping' => [
                'handling_days' => $handling,
                'free' => $policy?->method() === 'free',
                'excluded_states' => $policy?->excluded_states ?: [],
                'eta_from' => now()->addWeekdays(max(0, $handling) + 2)->toDateString(),
                'eta_to' => now()->addWeekdays(max(0, $handling) + 5)->toDateString(),
            ],
            'returns' => ['window_days' => (int) ($sp?->return_window_days ?? 30), 'shipping' => $sp?->return_shipping ?? 'buyer'],
            'vacation' => $sp?->isOnVacation() ? [
                'message' => $sp->vacation_message ?: 'This seller is away — dispatch may be delayed.',
                'until' => $sp->vacation_until?->toDateString(), 'paused' => $sp->salesPaused(),
            ] : null,
            'accepts_offers' => (bool) $product->accepts_offers && $variants->isEmpty(),
            'subscription' => $product->subscription_enabled && $variants->isEmpty() && $product->quantity > 0 ? [
                'pct' => (float) $product->subscription_discount_pct,
                'intervals' => collect(Subscription::INTERVALS)->map(fn ($label, $days) => ['days' => $days, 'label' => $label])->values(),
            ] : null,
            'volume_discounts' => $product->volumeDiscounts->map(fn ($t) => [
                'min_quantity' => (int) $t->min_quantity, 'type' => $t->discount_type, 'value' => (float) $t->discount_value,
            ])->values(),
            'attributes' => $product->attributeValues->map(fn ($av) => ['name' => $av->attribute?->name, 'value' => $av->value])->values(),
            'fitments' => $product->fitments->sortBy([['year', 'desc']])->map(fn ($v) => [
                'vehicle_id' => $v->id, 'year' => $v->year, 'make' => $v->make?->name, 'model' => $v->model?->name,
                'engine' => $v->engine?->name, 'trim' => $v->trim, 'position' => $v->pivot?->position, 'notes' => $v->pivot?->notes,
            ])->values(),
            'bundles' => $bundles->map(fn ($b) => [
                'id' => $b->id, 'title' => $b->title, 'price' => round($b->discountedPrice(), 2), 'full_price' => round($b->fullPrice(), 2),
                'savings' => round($b->savings(), 2), 'products' => $present->cards($b->products),
            ])->values(),
            'seller' => $sp ? $present->seller($sp) + ['id' => $product->seller_id] : null,
            'reviews' => [
                'average' => round((float) $product->avg_rating, 1),
                'count' => (int) $product->reviews_count,
                'breakdown' => collect(range(5, 1))->mapWithKeys(fn ($s) => [$s => (int) ($reviewCounts[$s] ?? 0)]),
            ],
            'questions_count' => $product->questions()->count(),
            'share_url' => route('products.show', $product->slug),
        ]]);
    }

    /** Recommendations under a product, loaded after the page so it opens fast. */
    public function related(int $id, RecommendationService $recs): JsonResponse
    {
        $product = Product::listed()->findOrFail($id);
        $present = new ShopPresenter;
        $load = fn ($items) => Product::listed()->whereIn('id', collect($items)->pluck('id'))
            ->with('seller.sellerProfile', 'media')->withCount('activeVariants')->get()
            ->sortBy(fn ($p) => collect($items)->pluck('id')->search($p->id))->values();

        $companions = $recs->companions($product, 6);
        $together = $load($companions['items']);
        $alsoViewed = $load($recs->alsoViewed($product->id));
        $sameCategory = Product::listed()->where('id', '!=', $product->id)->where('category_id', $product->category_id)
            ->with('seller.sellerProfile', 'media')->withCount('activeVariants')->take(8)->get();

        return response()->json([
            'frequently_bought' => $present->cards($together),
            'frequently_bought_title' => $companions['title'],
            'also_viewed' => $present->cards($alsoViewed),
            'related' => $present->cards($sameCategory),
        ]);
    }

    /** Products by id, in the order asked — for the recently viewed row the app keeps on the phone. */
    public function byIds(Request $request): JsonResponse
    {
        $ids = collect(explode(',', (string) $request->query('ids')))->map(fn ($v) => (int) $v)->filter()->unique()->take(30)->values();
        $products = Product::listed()->whereIn('id', $ids)->with('seller.sellerProfile', 'media')->withCount('activeVariants')->get()
            ->sortBy(fn ($p) => $ids->search($p->id))->values();

        return response()->json(['products' => (new ShopPresenter)->cards($products)]);
    }

    public function deals(Request $request): JsonResponse
    {
        $deals = Product::onDeal()->listed()->with('seller.sellerProfile', 'media')->withCount('activeVariants')
            ->orderByRaw('CASE WHEN deal_ends_at IS NULL THEN 1 ELSE 0 END, deal_ends_at ASC')
            ->orderByRaw('(price - sale_price) / price DESC')
            ->paginate(24, ['*'], 'page', (int) $request->input('page', 1));

        $present = new ShopPresenter;
        $out = ['products' => $present->cards($deals->getCollection()), 'meta' => ShopPresenter::page($deals)];

        if ($deals->currentPage() === 1) {
            $top = Product::onDeal()->listed()->with('seller.sellerProfile', 'media')->withCount('activeVariants')
                ->orderByRaw('(price - sale_price) / price DESC')->first();
            $out['deal_of_the_day'] = $top ? $present->prime([$top])->card($top) : null;
            $out['total'] = $deals->total();
        }

        return response()->json($out);
    }

    /** Suggestions while typing — the same search as the results page. */
    public function suggest(Request $request, CatalogSearch $search, GarageService $garage): JsonResponse
    {
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 80));
        if (mb_strlen($q) < 2) {
            return response()->json(['products' => [], 'categories' => []]);
        }

        $vehicleId = $garage->vehicleId();
        // Cache only what's the same for everyone; "fits" and "saved" are worked out per shopper below.
        $data = Cache::remember('app.suggest.'.md5(mb_strtolower($q)), 60, function () use ($q, $search) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';

            return [
                'ids' => $search->orderByRelevance($search->apply(Product::listed(), $q), $q)->take(6)->pluck('products.id')->all(),
                'categories' => Category::where('is_active', true)->where('name', 'like', $like)->orderBy('name')->take(4)
                    ->get(['name', 'slug'])->map(fn ($c) => ['name' => $c->name, 'slug' => $c->slug])->all(),
            ];
        });
        $ids = collect($data['ids']);
        $products = Product::listed()->whereIn('id', $ids)->with('media', 'seller.sellerProfile')->withCount('activeVariants')->get()
            ->sortBy(fn ($p) => $ids->search($p->id))->values();

        return response()->json([
            'products' => (new ShopPresenter)->cards($products),
            'categories' => $data['categories'],
            'vehicle' => $vehicleId ? $garage->label() : null,
        ]);
    }

    /** @return array<string, mixed>|null the admin's site-wide announcement while it's on */
    private function announcement(): ?array
    {
        $text = trim((string) config('marketplace.platform.announcement'));
        $until = config('marketplace.platform.announcement_until');
        if ($text === '' || ($until && now()->gt(\Illuminate\Support\Carbon::parse($until)->endOfDay()))) {
            return null;
        }
        $link = config('marketplace.platform.announcement_link');

        return [
            'text' => $text,
            'tone' => config('marketplace.platform.announcement_tone', 'accent'),
            'link' => $link ? \App\Http\Resources\Shop\AppLink::from($link) : null,
        ];
    }
}
