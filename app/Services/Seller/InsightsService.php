<?php

namespace App\Services\Seller;

use App\Models\Product;
use App\Models\Question;
use Illuminate\Support\Facades\DB;

class InsightsService
{
    /** Active listings that haven't sold in 60 days and are 45+ days old. */
    public function staleListings(int $sellerId)
    {
        // An unpaid or cancelled order isn't a sale.
        $soldRecently = DB::table('order_items')
            ->select('product_id')
            ->where('seller_id', $sellerId)
            ->whereNotIn('status', SellerAnalyticsService::excluded())
            ->where('created_at', '>=', now()->subDays(60));

        return Product::where('seller_id', $sellerId)
            ->where('status', 'active')
            ->where('quantity', '>', 0)
            ->where('created_at', '<=', now()->subDays(45))
            ->whereNotIn('id', $soldRecently)
            // Already marked down — no point suggesting another sale.
            ->where(fn ($q) => $q->whereNull('sale_price')->orWhere('deal_ends_at', '<', now()))
            ->orderByDesc('views_count')
            ->limit(12)
            ->get(['id', 'title', 'slug', 'price', 'sale_price', 'quantity', 'views_count', 'created_at']);
    }

    /** High-interest listings (views in the last 30 days) that are nearly out of stock. */
    public function hotLowStock(int $sellerId)
    {
        return Product::where('products.seller_id', $sellerId)
            ->where('products.status', 'active')
            ->whereBetween('products.quantity', [1, 3])
            ->leftJoin('product_views', fn ($j) => $j->on('product_views.product_id', '=', 'products.id')
                ->where('product_views.viewed_at', '>=', now()->subDays(30)))
            ->groupBy('products.id', 'products.title', 'products.slug', 'products.quantity')
            ->select('products.id', 'products.title', 'products.slug', 'products.quantity', DB::raw('COUNT(product_views.id) as recent_views'))
            ->orderByDesc('recent_views')
            ->limit(10)
            ->get();
    }

    /**
     * Shoppers are looking but not buying: 20+ unique visitors in 30 days, no sale. Usually the
     * price, photos or missing fitment — each row carries the listing's quality score to point at why.
     */
    public function trafficNoSales(int $sellerId)
    {
        $sold = DB::table('order_items')->select('product_id')->where('seller_id', $sellerId)
            ->whereNotIn('status', SellerAnalyticsService::excluded())
            ->where('created_at', '>=', now()->subDays(30));

        $rows = DB::table('product_views')
            ->join('products', 'products.id', '=', 'product_views.product_id')
            ->where('products.seller_id', $sellerId)->where('products.status', 'active')
            ->where('product_views.viewed_at', '>=', now()->subDays(30))
            ->whereNotIn('products.id', $sold)
            ->groupBy('products.id')
            ->havingRaw('COUNT(DISTINCT product_views.viewer_key) >= 20')
            ->selectRaw('products.id, COUNT(DISTINCT product_views.viewer_key) as visitors')
            ->orderByDesc('visitors')->limit(10)->get()->keyBy('id');

        return Product::with('media', 'fitments')->whereIn('id', $rows->keys())->get()
            ->map(fn ($p) => tap($p, function ($p) use ($rows) {
                $p->recent_visitors = (int) $rows[$p->id]->visitors;
                $p->quality = $p->qualityScore();
            }))
            ->sortByDesc('recent_visitors')->values();
    }

    /** Active listings with a weak listing-quality score — the quickest wins for search and conversion. */
    public function lowQuality(int $sellerId)
    {
        return Product::where('seller_id', $sellerId)->where('status', 'active')
            ->with('media', 'fitments')->latest()->limit(300)->get()
            ->map(fn ($p) => tap($p, fn ($p) => $p->quality = $p->qualityScore()))
            ->filter(fn ($p) => $p->quality['score'] < 60)
            ->sortBy(fn ($p) => $p->quality['score'])
            ->take(10)->values();
    }

    /** Buyer demand you're missing: wishlisted items now out of stock. */
    public function outOfStockDemand(int $sellerId)
    {
        return Product::where('products.seller_id', $sellerId)
            ->where('products.status', 'active')
            ->where('products.quantity', '<=', 0)
            ->join('wishlist_items', 'wishlist_items.product_id', '=', 'products.id')
            ->select('products.id', 'products.title', 'products.slug', DB::raw('COUNT(wishlist_items.id) as want_count'))
            ->groupBy('products.id', 'products.title', 'products.slug')
            ->orderByDesc('want_count')
            ->limit(10)
            ->get();
    }

    public function unansweredQuestions(int $sellerId)
    {
        return Question::whereHas('product', fn ($q) => $q->where('seller_id', $sellerId))
            ->where('status', 'published')
            // A shopper's answer doesn't count — buyers trust the seller's.
            ->whereDoesntHave('answers', fn ($a) => $a->where('is_seller', true))
            ->with('product:id,title,slug')
            ->latest()
            ->limit(10)
            ->get();
    }

    /** One-line headline numbers for the dashboard. */
    public function summary(int $sellerId): array
    {
        return [
            'stale' => $this->staleListings($sellerId)->count(),
            'hot_low_stock' => $this->hotLowStock($sellerId)->count(),
            'unmet_demand' => $this->outOfStockDemand($sellerId)->sum('want_count'),
            'unanswered' => $this->unansweredQuestions($sellerId)->count(),
        ];
    }
}
