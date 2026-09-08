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
        $cart->setQuantity($productId, $quantity);
    }

    public function remove(int $productId, CartService $cart): void
    {
        $cart->remove($productId);
    }

    public function render(CartService $cart)
    {
        $lines = $cart->lines()->map(fn ($line) => [
            'product_id' => $line['product']->id,
            'title' => $line['product']->title,
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
