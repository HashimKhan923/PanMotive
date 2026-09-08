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
            ->with([
                'brand', 'category.parent', 'seller.sellerProfile',
                'fitments.make', 'fitments.model', 'fitments.engine',
                'attributeValues.attribute',
            ])
            ->firstOrFail();

        $product->increment('views_count');

        $related = Product::where('status', 'active')
            ->where('id', '!=', $product->id)
            ->where('category_id', $product->category_id)
            ->with('brand')
            ->take(4)->get();

        return view('storefront.products.show', compact('product', 'related'));
    }
}
