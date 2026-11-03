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

    public function add(int $productId, int $quantity = 1, ?int $variantId = null): void
    {
        $cart = $this->currentCart();
        $item = $cart->items()->firstOrNew([
            'product_id' => $productId,
            'product_variant_id' => $variantId,
        ]);
        $item->quantity = max(1, ($item->quantity ?? 0) + $quantity);
        $item->save();
        $cart->touch();
    }

    public function setQuantity(int $productId, int $quantity, ?int $variantId = null): void
    {
        $cart = $this->currentCart();
        $match = ['product_id' => $productId, 'product_variant_id' => $variantId];

        if ($quantity <= 0) {
            $cart->items()->where($match)->delete();
        } else {
            $cart->items()->updateOrCreate($match, ['quantity' => $quantity]);
        }

        $cart->touch();
    }

    public function remove(int $productId, ?int $variantId = null): void
    {
        $cart = $this->currentCart();
        $cart->items()->where('product_id', $productId)->where('product_variant_id', $variantId)->delete();
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
        return $this->currentCart()->items()->with(['product.volumeDiscounts', 'variant'])->get();
    }

    /**
     * Resolved, priced lines for active products only.
     *
     * @return Collection<int, array{product: Product, quantity: int, unit_price: float, line_total: float}>
     */
    public function lines(): Collection
    {
        return $this->items()
            ->filter(fn ($item) => $item->product && $item->product->isListed())
            ->filter(fn ($item) => ! $item->product_variant_id || ($item->variant && $item->variant->is_active))
            ->map(function ($item) {
                $variant = $item->variant;

                $unit = $variant
                    ? $variant->effectivePrice()
                    : $item->product->unitPriceForQuantity($item->quantity);

                $listUnit = $variant
                    ? $variant->listPrice()
                    : (float) $item->product->price;

                return [
                    'product' => $item->product,
                    'variant' => $variant,
                    'quantity' => $item->quantity,
                    'unit_price' => $unit,
                    'list_unit_price' => $listUnit,
                    'line_total' => round($unit * $item->quantity, 2),
                ];
            })
            ->values();
    }

    public function subtotal(): float
    {
        return (float) $this->lines()->sum('line_total');
    }

    /**
     * Total savings from seller bundles whose full item set (with quantities) is
     * present in the cart.
     *
     * @return array{total: float, applied: array<int, array{title: string, savings: float}>}
     */
    public function bundleDiscount(?Collection $lines = null): array
    {
        $lines ??= $this->lines();
        if ($lines->isEmpty()) {
            return ['total' => 0.0, 'applied' => [], 'by_product' => []];
        }

        // Units in the cart per product — variant lines of the same product add up.
        $available = $lines->groupBy(fn ($l) => $l['product']->id)->map(fn ($g) => (int) $g->sum('quantity'));

        $bundles = \App\Models\Bundle::where('is_active', true)
            ->whereHas('items', fn ($q) => $q->whereIn('product_id', $available->keys()))
            ->with('items', 'products')
            ->get()
            ->filter(fn ($b) => $b->isAvailable())
            ->sortByDesc(fn ($b) => $b->savings()); // best deal first

        $applied = [];
        $byProduct = [];

        foreach ($bundles as $bundle) {
            // Each unit can only count toward one bundle: how many complete sets do the
            // units not already used by a better bundle still make?
            $sets = $bundle->items->min(fn ($i) => intdiv((int) ($available[$i->product_id] ?? 0), max(1, (int) $i->quantity)));
            if ($sets < 1) {
                continue;
            }

            foreach ($bundle->items as $i) {
                $available[$i->product_id] -= $i->quantity * $sets;
            }

            $saving = round($bundle->savings() * $sets, 2);
            $applied[] = ['bundle_id' => $bundle->id, 'title' => $bundle->title, 'sets' => $sets, 'savings' => $saving];

            // Attribute the saving to the member products by their share of the bundle price,
            // so it can be charged to exactly those order lines. Last one takes the rounding remainder.
            $full = max($bundle->fullPrice(), 0.01);
            $left = $saving;
            $members = $bundle->products->values();
            foreach ($members as $idx => $p) {
                $share = $idx === $members->count() - 1
                    ? $left
                    : round($saving * ($p->effectivePrice() * $p->pivot->quantity) / $full, 2);
                $left = round($left - $share, 2);
                $byProduct[$p->id] = round(($byProduct[$p->id] ?? 0) + $share, 2);
            }
        }

        return ['total' => round(collect($applied)->sum('savings'), 2), 'applied' => $applied, 'by_product' => $byProduct];
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
