<?php

namespace App\Livewire\Storefront;

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

    #[Url]
    public ?int $categoryId = null;

    #[Url]
    public ?int $vehicleId = null;

    #[Url]
    public string $sort = '';

    public string $fallbackNotice = '';

    public function updating($name): void
    {
        if (in_array($name, ['q', 'categoryId', 'vehicleId', 'sort'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function results(): LengthAwarePaginator
    {
        $this->fallbackNotice = '';

        $results = $this->runSearch($this->q);

        // Zero hits on a real query → ask the AI to turn a vague phrase into
        // catalog keywords rather than showing an empty page.
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
                if ($this->categoryId) {
                    $filters[] = "category_id = {$this->categoryId}";
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

                return $meilisearch->search($query, $options);
            })->paginate(24);
        } catch (\Throwable $e) {
            // Meilisearch unreachable / not configured → degrade to a DB query
            // so the storefront still works in dev and during outages.
            report($e);

            return $this->databaseSearch($q);
        }
    }

    protected function databaseSearch(string $q): LengthAwarePaginator
    {
        return Product::query()
            ->where('status', 'active')
            ->when($q !== '' && $q !== '*', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('title', 'like', "%{$q}%")
                        ->orWhere('part_number', 'like', "%{$q}%")
                        ->orWhere('oem_number', 'like', "%{$q}%");
                });
            })
            ->when($this->categoryId, fn ($query) => $query->where('category_id', $this->categoryId))
            ->when($this->vehicleId, fn ($query) => $query->whereHas('fitments', fn ($f) => $f->where('vehicles.id', $this->vehicleId)))
            ->when($this->sort === 'price_asc', fn ($query) => $query->orderBy('price'))
            ->when($this->sort === 'price_desc', fn ($query) => $query->orderByDesc('price'))
            ->when($this->sort === '', fn ($query) => $query->latest())
            ->paginate(24);
    }

    public function render()
    {
        return view('livewire.storefront.product-browse');
    }
}
