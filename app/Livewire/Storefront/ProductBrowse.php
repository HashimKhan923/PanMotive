<?php

namespace App\Livewire\Storefront;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\Search\SemanticFallbackService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.storefront')]
class ProductBrowse extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $q = '';

    #[Url(as: 'category')]
    public string $category = '';

    #[Url(as: 'brand')]
    public string $brand = '';

    #[Url]
    public string $condition = '';

    #[Url]
    public ?int $vehicleId = null;

    #[Url]
    public string $sort = '';

    public string $fallbackNotice = '';

    public function updating($name): void
    {
        if (in_array($name, ['q', 'category', 'brand', 'condition', 'vehicleId', 'sort'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['q', 'category', 'brand', 'condition', 'vehicleId', 'sort']);
        $this->resetPage();
    }

    public function getActiveFiltersProperty(): int
    {
        return collect([$this->q, $this->category, $this->brand, $this->condition, $this->vehicleId])
            ->filter()->count();
    }

    #[Computed]
    public function results(): LengthAwarePaginator
    {
        $this->fallbackNotice = '';
        $results = $this->runSearch($this->q);

        if ($results->total() === 0 && strlen($this->q) > 3 && config('services.anthropic.key')) {
            $rewritten = app(SemanticFallbackService::class)->rewriteQuery($this->q);
            if ($rewritten && $rewritten !== $this->q) {
                $results = $this->runSearch($rewritten);
                $this->fallbackNotice = "Showing results for \"{$rewritten}\"";
            }
        }

        return $results;
    }

    protected function runSearch(string $q): LengthAwarePaginator
    {
        try {
            return Product::search($q ?: '*', function ($meilisearch, $query, $options) {
                $filters = ['status = active'];
                if ($catId = $this->categoryId()) {
                    $filters[] = "category_id = {$catId}";
                }
                if ($brandId = $this->brandId()) {
                    $filters[] = "brand_id = {$brandId}";
                }
                if ($this->condition) {
                    $filters[] = "condition = {$this->condition}";
                }
                if ($this->vehicleId) {
                    $filters[] = "fitment_vehicle_ids = {$this->vehicleId}";
                }
                $options['filter'] = implode(' AND ', $filters);

                if ($this->sort === 'price_asc') {
                    $options['sort'] = ['price:asc'];
                }
                if ($this->sort === 'price_desc') {
                    $options['sort'] = ['price:desc'];
                }
                if ($this->sort === 'newest') {
                    $options['sort'] = ['created_at:desc'];
                }

                return $meilisearch->search($query, $options);
            })->paginate(15);
        } catch (\Throwable $e) {
            report($e);

            return $this->databaseSearch($q);
        }
    }

    protected function databaseSearch(string $q): LengthAwarePaginator
    {
        return Product::query()
            ->where('status', 'active')
            ->with('brand', 'seller.sellerProfile')
            ->when($q !== '' && $q !== '*', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('title', 'like', "%{$q}%")
                        ->orWhere('part_number', 'like', "%{$q}%")
                        ->orWhere('oem_number', 'like', "%{$q}%");
                });
            })
            ->when($this->categoryId(), fn ($query, $id) => $query->where('category_id', $id))
            ->when($this->brandId(), fn ($query, $id) => $query->where('brand_id', $id))
            ->when($this->condition, fn ($query) => $query->where('condition', $this->condition))
            ->when($this->vehicleId, fn ($query) => $query->whereHas('fitments', fn ($f) => $f->where('vehicles.id', $this->vehicleId)))
            ->when($this->sort === 'price_asc', fn ($query) => $query->orderBy('price'))
            ->when($this->sort === 'price_desc', fn ($query) => $query->orderByDesc('price'))
            ->when(in_array($this->sort, ['', 'newest'], true), fn ($query) => $query->latest())
            ->paginate(15);
    }

    protected function categoryId(): ?int
    {
        return $this->category ? Category::where('slug', $this->category)->value('id') : null;
    }

    protected function brandId(): ?int
    {
        return $this->brand ? Brand::where('slug', $this->brand)->value('id') : null;
    }

    public function render()
    {
        return view('livewire.storefront.product-browse', [
            'categories' => Category::whereNull('parent_id')->orderBy('name')->get(['id', 'name', 'slug']),
            'brands' => Brand::orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }
}
