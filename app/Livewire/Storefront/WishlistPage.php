<?php

namespace App\Livewire\Storefront;

use App\Services\Cart\CartService;
use App\Services\Cart\WishlistService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.storefront')]
class WishlistPage extends Component
{
    public function remove(int $productId, WishlistService $wishlist): void
    {
        $wishlist->remove($productId);
        $this->dispatch('wishlist-updated', count: $wishlist->count());
    }

    public function moveToCart(int $productId, WishlistService $wishlist, CartService $cart): void
    {
        $cart->add($productId, 1);
        $wishlist->remove($productId);
        $this->dispatch('cart-updated', count: $cart->itemCount());
        $this->dispatch('wishlist-updated', count: $wishlist->count());
    }

    public function render(WishlistService $wishlist)
    {
        return view('livewire.storefront.wishlist-page', [
            'products' => $wishlist->products(),
        ]);
    }
}
