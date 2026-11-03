<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Cart\CartService;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function add(Request $request, CartService $cart)
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'product_variant_id' => 'nullable|integer|exists:product_variants,id',
            'quantity' => 'nullable|integer|min:1|max:99',
        ]);

        $product = Product::where('id', $data['product_id'])->listed()->firstOrFail();

        // Quick-add from a product card posts JSON and expects JSON back (no page reload).
        $fail = fn (string $message) => $request->expectsJson()
            ? response()->json(['message' => $message], 422)
            : back()->withErrors(['product_variant_id' => $message]);

        if ($product->seller?->sellerProfile?->salesPaused()) {
            return $fail('This seller is away and not taking orders right now.');
        }

        $variantId = null;
        if ($product->hasVariants()) {
            $variant = $product->activeVariants()->find($data['product_variant_id'] ?? null);
            if (! $variant) {
                return $fail('Please choose an option.');
            }
            $variantId = $variant->id;
        }

        if (! $product->hasVariants() && (int) $product->quantity < 1) {
            return $fail('Sorry — that part just sold out.');
        }

        $cart->add($product->id, $data['quantity'] ?? 1, $variantId);

        if ($request->expectsJson()) {
            return response()->json(['count' => $cart->itemCount(), 'message' => 'Added to cart']);
        }

        return redirect()->route('cart.index')->with('success', 'Added to cart.');
    }

    /** One click: put every item of a seller's bundle in the cart so the bundle discount applies. */
    public function addBundle(\App\Models\Bundle $bundle, CartService $cart)
    {
        $bundle->load('products');
        abort_unless($bundle->is_active && $bundle->isAvailable(), 404);

        // Listings with options (colour, side…) need the buyer to choose — send them to pick.
        if ($bundle->products->contains(fn ($p) => $p->hasVariants())) {
            return back()->with('status', 'Some items in this bundle have options — add each one from its listing to choose.');
        }

        foreach ($bundle->products as $product) {
            $cart->add($product->id, (int) $product->pivot->quantity);
        }

        return redirect()->route('cart.index')
            ->with('success', "\"{$bundle->title}\" added — you're saving \$".number_format($bundle->savings(), 2).'.');
    }

    public function summary(CartService $cart)
    {
        $lines = $cart->lines();

        return response()->json([
            'count' => (int) $lines->sum('quantity'),
            'subtotal' => round($lines->sum('line_total'), 2),
            'items' => $lines->take(6)->map(fn ($l) => [
                'title' => $l['product']->title,
                'variant' => $l['variant']?->label,
                'qty' => $l['quantity'],
                'line_total' => $l['line_total'],
                'image' => $l['product']->getFirstMediaUrl('images', 'thumb') ?: $l['product']->getFirstMediaUrl('images'),
                'url' => route('products.show', $l['product']->slug),
            ])->values(),
        ]);
    }

    public function remove(Request $request, CartService $cart)
    {
        $data = $request->validate([
            'product_id' => 'required|integer',
            'product_variant_id' => 'nullable|integer',
        ]);

        $cart->remove($data['product_id'], $data['product_variant_id'] ?? null);

        return back()->with('success', 'Removed from cart.');
    }
}
