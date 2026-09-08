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
        return Product::where('status', 'active')
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

        return Product::whereIn('id', $coOccurringProductIds)->where('status', 'active')->get();
    }

    public function personalizedForBuyer(int $buyerId, int $limit = 8): Collection
    {
        $purchasedCategoryIds = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('orders.buyer_id', $buyerId)
            ->pluck('products.category_id')->unique();

        if ($purchasedCategoryIds->isEmpty()) {
            return Product::where('status', 'active')->inRandomOrder()->limit($limit)->get();
        }

        return Product::where('status', 'active')
            ->whereIn('category_id', $purchasedCategoryIds)
            ->inRandomOrder()->limit($limit)->get();
    }
}
