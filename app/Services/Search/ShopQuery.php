<?php

namespace App\Services\Search;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\AI\AiClient;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The shop's search + filters, shared by the website (Livewire ProductBrowse) and the shopping
 * app's API, so both list the same parts in the same order with the same counts.
 */
class ShopQuery
{
    public string $q = '';

    public string $category = '';

    public string $brand = '';

    public string $condition = '';

    public string $make = '';

    public string $model = '';

    public ?int $vehicleId = null;

    public string $sort = '';

    /** Set when the shopper's words found nothing and an AI rewrite did. */
    public string $fallbackNotice = '';

    /** The words actually searched — the shopper's, or the AI rewrite. */
    protected ?string $searchTerm = null;

    private ?Category $selectedCategoryCache = null;

    private bool $categoryResolved = false;

    public function __construct(private CatalogSearch $catalog) {}

    /** @param array<string, mixed> $filters */
    public static function make(array $filters): self
    {
        $query = app(self::class);
        foreach (['q', 'category', 'brand', 'condition', 'make', 'model', 'sort'] as $key) {
            $query->{$key} = trim((string) ($filters[$key] ?? ''));
        }
        $query->vehicleId = isset($filters['vehicleId']) && $filters['vehicleId'] !== '' && $filters['vehicleId'] !== null
            ? (int) $filters['vehicleId'] : null;

        return $query;
    }

    public function results(int $perPage = 15, ?int $page = null): LengthAwarePaginator
    {
        $this->fallbackNotice = '';
        $this->searchTerm = $this->q;
        $results = $this->runSearch($perPage, $page);

        if ($results->total() === 0 && ($rewritten = $this->rewrittenQuery())) {
            $this->searchTerm = $rewritten;
            $retry = $this->runSearch($perPage, $page);

            if ($retry->total() > 0) {
                $this->fallbackNotice = "No exact match for \"{$this->q}\". Showing results for \"{$rewritten}\".";

                return $retry;
            }
            $this->searchTerm = $this->q;
        }

        return $results;
    }

    protected function runSearch(int $perPage, ?int $page): LengthAwarePaginator
    {
        $query = $this->filtered()->with('brand', 'seller.sellerProfile', 'media')
            ->orderByRaw('CASE WHEN products.quantity > 0 THEN 0 ELSE 1 END');

        match ($this->sort) {
            'price_asc' => $query->orderByLivePrice('asc'),
            'price_desc' => $query->orderByLivePrice('desc'),
            'newest' => null,
            default => $this->catalog->orderByRelevance($query, (string) $this->searchTerm),
        };

        return $query->latest('products.created_at')->orderByDesc('products.id')->paginate($perPage, ['*'], 'page', $page);
    }

    /** The listings the current filters allow. $except leaves one filter out, which is what its own counts need. */
    public function filtered(?string $except = null): Builder
    {
        $query = Product::query()->listed()->original();

        $this->catalog->apply($query, $this->searchTerm ?? $this->q);

        if ($except !== 'category' && $this->category !== '') {
            $selected = $this->selectedCategory();
            $query->whereIn('products.category_id', $selected
                ? Category::where('parent_id', $selected->id)->pluck('id')->push($selected->id)
                : [0]);
        }
        if ($except !== 'brand' && $this->brand !== '') {
            $query->whereIn('products.brand_id', Brand::where('slug', $this->brand)->select('id'));
        }
        if ($except !== 'condition' && $this->condition !== '') {
            $query->where('products.condition', $this->condition);
        }
        if ($this->make !== '') {
            $make = $this->selectedMake();
            $make ? $query->forVehicleMake($make, $this->selectedModel()) : $query->whereRaw('1 = 0');
        }
        if ($this->vehicleId) {
            $query->whereHas('fitments', fn ($f) => $f->where('vehicles.id', $this->vehicleId));
        }

        return $query;
    }

    /** Ask the AI what a search that found nothing probably meant. Limited, since a miss spends money. */
    protected function rewrittenQuery(): ?string
    {
        if (mb_strlen(trim($this->q)) <= 3 || ! app(AiClient::class)->isConfigured()) {
            return null;
        }

        $rewritten = RateLimiter::attempt('search-rewrite:'.request()->ip(), 10,
            fn () => app(SemanticFallbackService::class)->rewriteQuery($this->q), 60);

        return is_string($rewritten) && strcasecmp($rewritten, trim($this->q)) !== 0 ? $rewritten : null;
    }

    public function selectedCategory(): ?Category
    {
        if (! $this->categoryResolved) {
            $this->selectedCategoryCache = $this->category !== '' ? Category::where('slug', $this->category)->first() : null;
            $this->categoryResolved = true;
        }

        return $this->selectedCategoryCache;
    }

    public function selectedMake(): ?VehicleMake
    {
        return $this->make !== '' ? VehicleMake::where('slug', $this->make)->first() : null;
    }

    public function selectedModel(): ?VehicleModel
    {
        $make = $this->selectedMake();

        return $make && $this->model !== ''
            ? VehicleModel::where('vehicle_make_id', $make->id)->where('slug', $this->model)->first()
            : null;
    }

    /** Root categories with live counts. Empty ones are hidden; a selected branch also lists its subcategories. */
    public function categoryTree(): Collection
    {
        $selected = $this->selectedCategory();
        $counts = $this->filtered('category')->reorder()
            ->selectRaw('products.category_id, COUNT(*) AS aggregate')
            ->groupBy('products.category_id')->pluck('aggregate', 'category_id');

        $all = Category::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'parent_id', 'name', 'slug']);
        $openRoot = $selected?->parent_id ?: $selected?->id;

        return $all->whereNull('parent_id')->map(function (Category $root) use ($all, $counts, $selected, $openRoot) {
            $children = $all->where('parent_id', $root->id)->values();
            $children->each(fn ($c) => $c->listings_count = (int) ($counts[$c->id] ?? 0));

            $root->listings_count = (int) ($counts[$root->id] ?? 0) + $children->sum('listings_count');
            $root->shown_children = $root->id === $openRoot
                ? $children->filter(fn ($c) => $c->listings_count > 0 || $c->id === $selected?->id)->values()
                : collect();

            return $root;
        })->filter(fn ($root) => $root->listings_count > 0 || $root->id === $openRoot)->values();
    }

    /** @return Collection<string, int> listings per condition under the other filters */
    public function conditionCounts(): Collection
    {
        return $this->filtered('condition')->reorder()
            ->select('products.condition')->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('products.condition')->pluck('aggregate', 'condition');
    }

    /** @return Collection<int, int> listings per brand id under the other filters */
    public function brandCounts(): Collection
    {
        return $this->filtered('brand')->reorder()->whereNotNull('products.brand_id')
            ->selectRaw('products.brand_id, COUNT(*) AS aggregate')
            ->groupBy('products.brand_id')->pluck('aggregate', 'brand_id');
    }
}
