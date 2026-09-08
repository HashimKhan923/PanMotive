<?php

namespace App\Livewire\Storefront;

use App\Models\Product;
use App\Support\Cart;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.storefront')]
class CartPage extends Component
{
    public function updateQuantity(int $productId, int $quantity, Cart $cart): void
    {
        $cart->setQuantity($productId, $quantity);
    }

    public function remove(int $productId, Cart $cart): void
    {
        $cart->remove($productId);
    }

    public function render(Cart $cart)
    {
        $raw = collect($cart->items());

        $products = Product::whereIn('id', $raw->pluck('product_id'))->get()->keyBy('id');

        $lines = $raw
            ->filter(fn ($i) => $products->has($i['product_id']))
            ->map(function ($i) use ($products) {
                $product = $products[$i['product_id']];
                $unit = (float) ($product->sale_price ?? $product->price);

                return [
                    'product_id' => $product->id,
                    'title' => $product->title,
                    'unit_price' => $unit,
                    'quantity' => $i['quantity'],
                    'line_total' => round($unit * $i['quantity'], 2),
                ];
            })
            ->values();

        return view('livewire.storefront.cart-page', [
            'lines' => $lines,
            'subtotal' => $lines->sum('line_total'),
        ]);
    }
}
