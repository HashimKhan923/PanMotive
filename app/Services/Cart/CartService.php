<?php

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * DB-backed cart. One row per user (or per guest session), so it survives across
 * devices, feeds abandoned-cart recovery, and lets checkout read a real subtotal.
 * A guest cart is merged into the user's cart on the first authenticated resolve.
 */
class CartService
{
    private ?Cart $cart = null;

    public function currentCart(): Cart
    {
        if ($this->cart) {
            return $this->cart;
        }

        if ($user = auth()->user()) {
            $cart = Cart::firstOrCreate(['user_id' => $user->id]);
            $this->mergeGuestCartInto($cart);

            return $this->cart = $cart;
        }

        $sessionId = session('cart_session_id');

        if (! $sessionId) {
            session(['cart_session_id' => $sessionId = (string) Str::uuid()]);
        }

        return $this->cart = Cart::firstOrCreate(['session_id' => $sessionId]);
    }

    public function add(int $productId, int $quantity = 1): void
    {
        $cart = $this->currentCart();
        $item = $cart->items()->firstOrNew(['product_id' => $productId]);
        $item->quantity = max(1, ($item->quantity ?? 0) + $quantity);
        $item->save();
        $cart->touch();
    }

    public function setQuantity(int $productId, int $quantity): void
    {
        $cart = $this->currentCart();

        if ($quantity <= 0) {
            $cart->items()->where('product_id', $productId)->delete();
        } else {
            $cart->items()->updateOrCreate(['product_id' => $productId], ['quantity' => $quantity]);
        }

        $cart->touch();
    }

    public function remove(int $productId): void
    {
        $cart = $this->currentCart();
        $cart->items()->where('product_id', $productId)->delete();
        $cart->touch();
    }

    public function clear(): void
    {
        $this->currentCart()->items()->delete();
    }

    /**
     * @return Collection<int, \App\Models\CartItem>
     */
    public function items(): Collection
    {
        return $this->currentCart()->items()->with('product')->get();
    }

    /**
     * Resolved, priced lines for active products only.
     *
     * @return Collection<int, array{product: Product, quantity: int, unit_price: float, line_total: float}>
     */
    public function lines(): Collection
    {
        return $this->items()
            ->filter(fn ($item) => $item->product && $item->product->status === 'active')
            ->map(function ($item) {
                $unit = (float) ($item->product->sale_price ?? $item->product->price);

                return [
                    'product' => $item->product,
                    'quantity' => $item->quantity,
                    'unit_price' => $unit,
                    'line_total' => round($unit * $item->quantity, 2),
                ];
            })
            ->values();
    }

    public function subtotal(): float
    {
        return (float) $this->lines()->sum('line_total');
    }

    public function isEmpty(): bool
    {
        return $this->currentCart()->items()->count() === 0;
    }

    public function count(): int
    {
        return (int) $this->currentCart()->items()->sum('quantity');
    }

    /**
     * Item count without creating a cart row — safe to call on every page render
     * (e.g. the header badge) for guests who have never added anything.
     */
    public function itemCount(): int
    {
        $cart = $this->existingCart();

        return $cart ? (int) $cart->items()->sum('quantity') : 0;
    }

    private function existingCart(): ?Cart
    {
        if ($user = auth()->user()) {
            return $user->cart()->first();
        }

        $sessionId = session('cart_session_id');

        return $sessionId ? Cart::where('session_id', $sessionId)->first() : null;
    }

    private function mergeGuestCartInto(Cart $userCart): void
    {
        $sessionId = session('cart_session_id');

        if (! $sessionId) {
            return;
        }

        $guest = Cart::where('session_id', $sessionId)->with('items')->first();

        if ($guest && $guest->id !== $userCart->id) {
            foreach ($guest->items as $item) {
                $existing = $userCart->items()->firstOrNew(['product_id' => $item->product_id]);
                $existing->quantity = ($existing->quantity ?? 0) + $item->quantity;
                $existing->save();
            }
            $guest->delete();
        }

        session()->forget('cart_session_id');
    }
}
