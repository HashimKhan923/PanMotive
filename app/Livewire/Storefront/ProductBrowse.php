<?php

namespace App\Livewire\Storefront;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Vehicle;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Search\ShopQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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

    protected ?ShopQuery $shopQuery = null;

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
        $search = $this->shopQuery();
        $results = $search->results(15);
        $this->fallbackNotice = $search->fallbackNotice;

        return $results;
    }

    /** The shared search + filters (also used by the shopping app's API), built from this page's filters. */
    protected function shopQuery(): ShopQuery
    {
        return $this->shopQuery ??= ShopQuery::make([
            'q' => $this->q, 'category' => $this->category, 'brand' => $this->brand, 'condition' => $this->condition,
            'make' => $this->make, 'model' => $this->model, 'vehicleId' => $this->vehicleId, 'sort' => $this->sort,
        ]);
    }

    /** The listings the current filters allow. $except leaves one filter out, which is what its own counts need. */
    protected function filtered(?string $except = null): Builder
    {
        return $this->shopQuery()->filtered($except);
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

    protected function selectedCategory(): ?Category
    {
        return $this->shopQuery()->selectedCategory();
    }

    protected function selectedMake(): ?VehicleMake
    {
        return $this->shopQuery()->selectedMake();
    }

    protected function selectedModel(): ?VehicleModel
    {
        return $this->shopQuery()->selectedModel();
    }

    public function render()
    {
        $results = $this->results; // first: the counts below must use the same search words
        $selected = $this->selectedCategory();

        $brandCounts = $this->shopQuery()->brandCounts();
        $conditionCounts = $this->shopQuery()->conditionCounts();

        return view('livewire.storefront.product-browse', [
            'results' => $results,
            'selectedCategory' => $selected,
            'parentCategory' => $selected?->parent_id ? Category::find($selected->parent_id) : null,
            'categories' => $this->shopQuery()->categoryTree(),
            'brands' => Brand::orderBy('name')->get(['id', 'name', 'slug'])
                ->each(fn ($b) => $b->listings_count = (int) ($brandCounts[$b->id] ?? 0))
                ->filter(fn ($b) => $b->listings_count > 0 || $b->slug === $this->brand)->values(),
            'conditionCounts' => $conditionCounts,
            'totalForCondition' => $conditionCounts->sum(),
        ])->title(match (true) {
            trim($this->q) !== '' => '"'.\Illuminate\Support\Str::limit(trim($this->q), 60).'" parts — '.config('app.name'),
            $selected !== null => $selected->name.' parts — '.config('app.name'),
            default => 'Shop all auto parts — '.config('app.name'),
        });
    }
}
