<?php

namespace App\Livewire\Storefront;

use App\Services\Cart\CartService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.storefront')]
class CartPage extends Component
{
    public function updateQuantity(int $productId, int $quantity, CartService $cart): void
    {
        $cart->setQuantity($productId, max(0, $quantity));
        $this->dispatch('cart-updated', count: $cart->itemCount());
    }

    public function remove(int $productId, CartService $cart): void
    {
        $cart->remove($productId);
        $this->dispatch('cart-updated', count: $cart->itemCount());
    }

    public function render(CartService $cart)
    {
        $lines = $cart->lines()->map(fn ($line) => [
            'product_id' => $line['product']->id,
            'slug' => $line['product']->slug,
            'title' => $line['product']->title,
            'image' => $line['product']->getFirstMediaUrl('images', 'thumb') ?: $line['product']->getFirstMediaUrl('images'),
            'unit_price' => $line['unit_price'],
            'quantity' => $line['quantity'],
            'line_total' => $line['line_total'],
        ]);

        return view('livewire.storefront.cart-page', [
            'lines' => $lines,
            'subtotal' => $lines->sum('line_total'),
        ]);
    }
}
