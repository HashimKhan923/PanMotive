<?php

namespace App\Services\Recommendations;

use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rule-based on purpose: PDP recommendations need to be instant, so no
 * per-request AI. AI is reserved for offline work (SEO, listing drafts).
 */
class RecommendationService
{
    public function forVehicle(int $vehicleId, int $limit = 8): Collection
    {
        return Product::listed()
            ->whereHas('fitments', fn ($q) => $q->where('vehicles.id', $vehicleId))
            ->inRandomOrder()->limit($limit)->get();
    }

    /** Products frequently bought alongside this one, derived from order history. */
    public function frequentlyBoughtWith(int $productId, int $limit = 6): Collection
    {
        $coOccurringProductIds = DB::table('order_items as oi1')
            ->join('order_items as oi2', 'oi1.order_id', '=', 'oi2.order_id')
            ->where('oi1.product_id', $productId)
            ->where('oi2.product_id', '!=', $productId)
            ->select('oi2.product_id', DB::raw('COUNT(*) as freq'))
            ->groupBy('oi2.product_id')
            ->orderByDesc('freq')
            ->limit($limit)
            ->pluck('product_id');

        return Product::whereIn('id', $coOccurringProductIds)->listed()->get();
    }

    /** "Customers who viewed this also viewed" — co-view affinity from the view log. */
    public function alsoViewed(int $productId, int $limit = 6): Collection
    {
        $since = now()->subDays(60);

        $viewerKeys = DB::table('product_views')
            ->where('product_id', $productId)
            ->where('viewed_at', '>=', $since)
            ->distinct()
            ->pluck('viewer_key');

        if ($viewerKeys->isEmpty()) {
            return new Collection;
        }

        $ids = DB::table('product_views')
            ->whereIn('viewer_key', $viewerKeys)
            ->where('product_id', '!=', $productId)
            ->where('viewed_at', '>=', $since)
            ->select('product_id', DB::raw('COUNT(*) as freq'))
            ->groupBy('product_id')
            ->orderByDesc('freq')
            ->limit($limit)
            ->pluck('product_id')
            ->all();

        if (! $ids) {
            return new Collection;
        }

        return Product::whereIn('id', $ids)->listed()->with('brand')
            ->get()->sortBy(fn ($p) => array_search($p->id, $ids))->values();
    }

    public function personalizedForBuyer(int $buyerId, int $limit = 8): Collection
    {
        $purchasedCategoryIds = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('orders.buyer_id', $buyerId)
            ->pluck('products.category_id')->unique();

        if ($purchasedCategoryIds->isEmpty()) {
            return Product::listed()->inRandomOrder()->limit($limit)->get();
        }

        return Product::listed()
            ->whereIn('category_id', $purchasedCategoryIds)
            ->inRandomOrder()->limit($limit)->get();
    }
}
