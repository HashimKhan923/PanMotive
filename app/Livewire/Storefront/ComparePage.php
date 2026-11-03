<?php

namespace App\Livewire\Storefront;

use App\Models\Product;
use App\Services\Cart\CartService;
use App\Support\Compare;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.storefront')]
class ComparePage extends Component
{
    public function remove(int $productId, Compare $compare): void
    {
        $compare->remove($productId);
        $this->dispatch('compare-updated', count: $compare->count());
    }

    public function clear(Compare $compare): void
    {
        $compare->clear();
        $this->dispatch('compare-updated', count: 0);
    }

    public function addToCart(int $productId, CartService $cart): void
    {
        $cart->add($productId, 1);
        $this->dispatch('cart-updated', count: $cart->itemCount());
        $this->dispatch('toast', message: 'Added to cart');
    }

    public function render(Compare $compare)
    {
        $products = Product::query()
            ->whereIn('id', $compare->ids())
            ->listed()
            ->with(['brand', 'seller.sellerProfile', 'attributeValues.attribute', 'fitments'])
            ->get();

        // Union of attribute names across all compared products, for matrix rows.
        $attributeNames = $products
            ->flatMap(fn ($p) => $p->attributeValues->map(fn ($av) => $av->attribute?->name))
            ->filter()
            ->unique()
            ->values();

        return view('livewire.storefront.compare-page', [
            'products' => $products,
            'attributeNames' => $attributeNames,
        ]);
    }
}
