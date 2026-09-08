<?php

namespace App\Livewire\Storefront;

use App\Models\Product;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.storefront')]
class ProductBrowse extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $products = Product::query()
            ->where('status', 'active')
            ->when($this->search !== '', function ($query) {
                $query->where(function ($q) {
                    $q->where('title', 'like', "%{$this->search}%")
                        ->orWhere('part_number', 'like', "%{$this->search}%")
                        ->orWhere('oem_number', 'like', "%{$this->search}%");
                });
            })
            ->latest()
            ->paginate(12);

        return view('livewire.storefront.product-browse', compact('products'));
    }
}
