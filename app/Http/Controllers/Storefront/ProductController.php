<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Marketing\OffsiteAdsService;
use App\Services\RecentlyViewedService;
use Illuminate\Support\Facades\Cookie;

class ProductController extends Controller
{
    public function show(string $slug, RecentlyViewedService $recentlyViewed)
    {
        $product = Product::query()
            ->where('slug', $slug)
            ->listed()
            ->with([
                'brand', 'category.parent', 'seller.sellerProfile',
                'fitments.make', 'fitments.model', 'fitments.engine',
                'attributeValues.attribute', 'volumeDiscounts', 'activeVariants',
            ])
            ->firstOrFail();

        $bundles = \App\Models\Bundle::where('is_active', true)
            ->whereHas('items', fn ($q) => $q->where('product_id', $product->id))
            ->with('products')->get()
            // Only offer bundles a buyer can actually complete right now.
            ->filter(fn ($b) => $b->isAvailable());

        $product->increment('views_count');

        $viewerKey = auth()->id() ? 'u'.auth()->id() : 's'.substr(session()->getId(), 0, 40);

        \App\Models\ProductView::create([
            'product_id' => $product->id,
            'viewer_key' => $viewerKey,
            'viewed_at' => now(),
        ]);

        // Arrived from one of our Offsite Ads (feed links carry ?oa=<channel>): remember
        // the seller for the attribution window so a later order is credited to the ad.
        if (request()->filled('oa')) {
            $cookie = app(OffsiteAdsService::class)->recordClick(
                $product, (string) request('oa'), $viewerKey, request()->cookie(OffsiteAdsService::COOKIE)
            );
            if ($cookie) {
                Cookie::queue(OffsiteAdsService::COOKIE, $cookie, 60 * 24 * (int) config('marketplace.offsite_ads.attribution_days'));
            }
        }

        $related = Product::listed()
            ->where('id', '!=', $product->id)
            ->where('category_id', $product->category_id)
            ->with('brand')
            ->take(4)->get();

        $recent = $recentlyViewed->products(excludeId: $product->id, limit: 6);
        $recentlyViewed->push($product->id);

        return view('storefront.products.show', compact('product', 'related', 'recent', 'bundles'));
    }
}
