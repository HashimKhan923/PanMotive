<?php

namespace App\Livewire\Storefront;

use App\Models\Product;
use App\Services\Cart\CartService;
use Livewire\Component;

class AddToCartButton extends Component
{
    public Product $product;

    public bool $added = false;

    public function add(CartService $cart): void
    {
        if ($this->product->status !== 'active') {
            return;
        }

        $cart->add($this->product->id, 1);
        $this->added = true;

        $this->dispatch('cart-updated', count: $cart->itemCount());
    }

    public function render()
    {
        return view('livewire.storefront.add-to-cart-button');
    }
}
