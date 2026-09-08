<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $sellerId = auth()->id();

        $stats = [
            'active_listings' => Product::where('seller_id', $sellerId)->where('status', 'active')->count(),
            'pending_orders' => OrderItem::where('seller_id', $sellerId)->where('status', 'placed')->count(),
            'lifetime_sales' => OrderItem::where('seller_id', $sellerId)->whereIn('status', ['delivered', 'payout_released'])->sum('line_total'),
            'pending_payout' => OrderItem::where('seller_id', $sellerId)->whereIn('status', ['shipped', 'out_for_delivery', 'delivered'])->sum('seller_payout_amount'),
            'released_payout' => OrderItem::where('seller_id', $sellerId)->where('status', 'payout_released')->sum('seller_payout_amount'),
        ];

        $salesTrend = OrderItem::where('seller_id', $sellerId)
            ->where('created_at', '>=', now()->subDays(30))
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(line_total) as total'))
            ->groupBy('date')->orderBy('date')->get();

        $topProducts = Product::where('seller_id', $sellerId)
            ->withCount('orderItems')
            ->orderByDesc('order_items_count')
            ->limit(5)->get();

        return view('seller.dashboard', compact('stats', 'salesTrend', 'topProducts'));
    }
}
