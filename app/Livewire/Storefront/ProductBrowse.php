<?php

namespace App\Livewire\Storefront;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Vehicle;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\AI\AiClient;
use App\Services\Search\CatalogSearch;
use App\Services\Search\SemanticFallbackService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.storefront')]
class ProductBrowse extends Component
{
    use WithPagination;

    private const FILTERS = ['q', 'category', 'brand', 'condition', 'make', 'model', 'vehicleId'];

    #[Url(as: 'q')]
    public string $q = '';

    #[Url(as: 'category')]
    public string $category = '';

    #[Url(as: 'brand')]
    public string $brand = '';

    #[Url]
    public string $condition = '';

    #[Url]
    public string $make = '';

    #[Url]
    public string $model = '';

    #[Url]
    public ?int $vehicleId = null;

    #[Url]
    public string $sort = '';

    public string $fallbackNotice = '';

    /** The words actually searched — the shopper's, or the AI rewrite when theirs found nothing. */
    protected ?string $searchTerm = null;

    protected ?CatalogSearch $catalog = null;

    public function updating($name): void
    {
        if (in_array($name, [...self::FILTERS, 'sort'], true)) {
            $this->resetPage();
        }
    }

    public function updatedMake(): void
    {
        $this->model = '';
    }

    public function clearFilters(): void
    {
        $this->reset([...self::FILTERS, 'sort']);
        $this->resetPage();
    }

    public function removeFilter(string $filter): void
    {
        if (in_array($filter, self::FILTERS, true)) {
            $this->reset($filter === 'make' ? ['make', 'model'] : [$filter]);
            $this->resetPage();
        }
    }

    public function getActiveFiltersProperty(): int
    {
        return collect(self::FILTERS)->filter(fn ($f) => $this->{$f})->count();
    }

    /** @return list<array{filter: string, label: string}> what's narrowing the results, for the removable chips */
    #[Computed]
    public function chips(): array
    {
        $chips = [];
        if ($this->q !== '') {
            $chips[] = ['filter' => 'q', 'label' => "\"{$this->q}\""];
        }
        if ($this->category !== '') {
            $chips[] = ['filter' => 'category', 'label' => $this->selectedCategory()?->name ?? $this->category];
        }
        if ($this->brand !== '') {
            $chips[] = ['filter' => 'brand', 'label' => Brand::where('slug', $this->brand)->value('name') ?? $this->brand];
        }
        if ($this->condition !== '') {
            $chips[] = ['filter' => 'condition', 'label' => ucfirst($this->condition)];
        }
        if ($this->make !== '') {
            $chips[] = ['filter' => 'make', 'label' => trim(($this->selectedMake()?->name ?? $this->make).' '.($this->selectedModel()?->name ?? ''))];
        }
        if ($this->vehicleId) {
            $vehicle = Vehicle::with('make', 'model')->find($this->vehicleId);
            $chips[] = ['filter' => 'vehicleId', 'label' => $vehicle ? 'Fits '.$vehicle->label() : 'Selected vehicle'];
        }

        return $chips;
    }

    #[Computed]
    public function results(): LengthAwarePaginator
    {
        $this->fallbackNotice = '';
        $this->searchTerm = $this->q;
        $results = $this->runSearch();

        if ($results->total() === 0 && ($rewritten = $this->rewrittenQuery())) {
            $this->searchTerm = $rewritten;
            $retry = $this->runSearch();

            if ($retry->total() > 0) {
                $this->fallbackNotice = "No exact match for \"{$this->q}\". Showing results for \"{$rewritten}\".";

                return $retry;
            }
            $this->searchTerm = $this->q;
        }

        return $results;
    }

    protected function runSearch(): LengthAwarePaginator
    {
        $query = $this->filtered()->with('brand', 'seller.sellerProfile', 'media')
            ->orderByRaw('CASE WHEN products.quantity > 0 THEN 0 ELSE 1 END');

        match ($this->sort) {
            'price_asc' => $query->orderByLivePrice('asc'),
            'price_desc' => $query->orderByLivePrice('desc'),
            'newest' => null,
            default => $this->catalog()->orderByRelevance($query, (string) $this->searchTerm),
        };

        return $query->latest('products.created_at')->orderByDesc('products.id')->paginate(15);
    }

    /** The listings the current filters allow. $except leaves one filter out, which is what its own counts need. */
    protected function filtered(?string $except = null): Builder
    {
        $query = Product::query()->listed();

        $this->catalog()->apply($query, $this->searchTerm ?? $this->q);

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

    /** Sponsored placements (boosts + targeted ad campaigns) above organic results — only ones the filters allow. */
    #[Computed]
    public function sponsored()
    {
        if ($this->getPage() > 1) {
            return collect();
        }

        $ads = app(\App\Services\Seller\AdServingService::class);
        $served = $ads->forPlacement($this->category ? 'category' : 'search', [
            'category_id' => $this->selectedCategory()?->id,
            'q' => $this->q,
        ], 12);

        $allowed = $this->filtered()->where('products.quantity', '>', 0)
            ->whereIn('products.id', $served->map(fn ($ad) => $ad['product']->id))
            ->pluck('id')->flip();

        $served = $served->filter(fn ($ad) => $allowed->has($ad['product']->id))->take(3)->values();

        $ads->recordImpressions($served);

        return $served;
    }

    protected function catalog(): CatalogSearch
    {
        return $this->catalog ??= app(CatalogSearch::class);
    }

    protected function selectedCategory(): ?Category
    {
        return $this->category !== '' ? Category::where('slug', $this->category)->first() : null;
    }

    protected function selectedMake(): ?VehicleMake
    {
        return $this->make !== '' ? VehicleMake::where('slug', $this->make)->first() : null;
    }

    protected function selectedModel(): ?VehicleModel
    {
        $make = $this->selectedMake();

        return $make && $this->model !== ''
            ? VehicleModel::where('vehicle_make_id', $make->id)->where('slug', $this->model)->first()
            : null;
    }

    /** Root categories with live counts. Empty ones are hidden; a selected branch also lists its subcategories. */
    protected function categoryTree(?Category $selected): Collection
    {
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

    public function render()
    {
        $results = $this->results; // first: the counts below must use the same search words
        $selected = $this->selectedCategory();

        $brandCounts = $this->filtered('brand')->reorder()->whereNotNull('products.brand_id')
            ->selectRaw('products.brand_id, COUNT(*) AS aggregate')
            ->groupBy('products.brand_id')->pluck('aggregate', 'brand_id');

        $conditionCounts = $this->filtered('condition')->reorder()
            ->select('products.condition')->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('products.condition')->pluck('aggregate', 'condition');

        return view('livewire.storefront.product-browse', [
            'results' => $results,
            'selectedCategory' => $selected,
            'parentCategory' => $selected?->parent_id ? Category::find($selected->parent_id) : null,
            'categories' => $this->categoryTree($selected),
            'brands' => Brand::orderBy('name')->get(['id', 'name', 'slug'])
                ->each(fn ($b) => $b->listings_count = (int) ($brandCounts[$b->id] ?? 0))
                ->filter(fn ($b) => $b->listings_count > 0 || $b->slug === $this->brand)->values(),
            'conditionCounts' => $conditionCounts,
            'totalForCondition' => $conditionCounts->sum(),
        ]);
    }
}
