<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;

class ProductController extends Controller
{
    public function show(string $slug)
    {
        $product = Product::query()
            ->where('slug', $slug)
            ->where('status', 'active')
            ->with(['brand', 'category', 'seller', 'fitments.make', 'fitments.model'])
            ->firstOrFail();

        $product->increment('views_count');

        return view('storefront.products.show', compact('product'));
    }
}
