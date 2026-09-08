<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\Cart;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function add(Request $request, Cart $cart)
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'quantity' => 'nullable|integer|min:1|max:99',
        ]);

        $product = Product::where('id', $data['product_id'])->where('status', 'active')->firstOrFail();

        $cart->add($product->id, $data['quantity'] ?? 1);

        return redirect()->route('cart.index')->with('success', 'Added to cart.');
    }

    public function remove(Request $request, Cart $cart)
    {
        $data = $request->validate(['product_id' => 'required|integer']);

        $cart->remove($data['product_id']);

        return back()->with('success', 'Removed from cart.');
    }
}
