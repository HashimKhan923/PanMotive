<?php

namespace App\Livewire\Storefront;

use App\Services\Cart\CartService;
use App\Services\Cart\WishlistService;
use App\Services\Shipping\ShippingEstimateService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.storefront')]
class CartPage extends Component
{
    public function updateQuantity(int $productId, int $quantity, CartService $cart, ?int $variantId = null): void
    {
        $cart->setQuantity($productId, max(0, $quantity), $variantId);
        $this->dispatch('cart-updated', count: $cart->itemCount());
    }

    public function remove(int $productId, CartService $cart, ?int $variantId = null): void
    {
        $cart->remove($productId, $variantId);
        $this->dispatch('cart-updated', count: $cart->itemCount());
    }

    public function moveToWishlist(int $productId, CartService $cart, WishlistService $wishlist, ?int $variantId = null): void
    {
        if (! $wishlist->has($productId)) {
            $wishlist->toggle($productId);
        }
        $cart->remove($productId, $variantId);
        $this->dispatch('cart-updated', count: $cart->itemCount());
        $this->dispatch('wishlist-updated', count: $wishlist->count());
        $this->dispatch('toast', message: 'Moved to saved items');
    }

    public function render(CartService $cart, ShippingEstimateService $shipping)
    {
        $rawLines = $cart->lines();

        $groups = $rawLines
            ->groupBy(fn ($line) => $line['product']->seller_id)
            ->map(fn ($lines) => [
                'seller' => $lines->first()['product']->seller,
                'lines' => $lines->map(fn ($line) => [
                    'product_id' => $line['product']->id,
                    'variant_id' => $line['variant']?->id,
                    'slug' => $line['product']->slug,
                    'title' => $line['product']->title,
                    'variant_label' => $line['variant']?->label,
                    'image' => $line['product']->getFirstMediaUrl('images', 'thumb') ?: $line['product']->getFirstMediaUrl('images'),
                    'unit_price' => $line['unit_price'],
                    'list_unit_price' => $line['list_unit_price'] ?? $line['unit_price'],
                    'quantity' => $line['quantity'],
                    'line_total' => $line['line_total'],
                    'max' => $line['variant']?->quantity ?? $line['product']->quantity,
                ])->values(),
                'subtotal' => round($lines->sum('line_total'), 2),
            ])
            ->values();

        $estimate = $shipping->estimate($rawLines, auth()->user());
        $subtotal = round($rawLines->sum('line_total'), 2);
        $bundle = $cart->bundleDiscount();

        return view('livewire.storefront.cart-page', [
            'groups' => $groups,
            'subtotal' => $subtotal,
            'shipping' => $estimate['shipping'],
            'shippingFree' => $estimate['free'],
            'freeThreshold' => $estimate['threshold'],
            'bundleSavings' => $bundle['total'],
            'bundlesApplied' => $bundle['applied'],
            'estimatedTotal' => round($subtotal + $estimate['shipping'] - $bundle['total'], 2),
        ]);
    }
}
