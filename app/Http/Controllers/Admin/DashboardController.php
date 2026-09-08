<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\AdminAlert;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $stats = [
            'gmv_30d' => Order::where('created_at', '>=', now()->subDays(30))
                ->whereNotIn('status', ['cancelled'])->sum('total'),

            'commission_revenue_30d' => OrderItem::where('created_at', '>=', now()->subDays(30))
                ->whereIn('status', [OrderStatus::Delivered, OrderStatus::PayoutReleased])
                ->sum('platform_fee_amount'),

            'active_sellers' => SellerProfile::where('status', 'approved')->count(),
            'pending_seller_approvals' => SellerProfile::where('status', 'pending')->count(),

            'orders_today' => Order::whereDate('created_at', today())->count(),
            'new_buyers_30d' => User::role('buyer')->where('created_at', '>=', now()->subDays(30))->count(),

            'open_disputes' => OrderItem::whereIn('status', [OrderStatus::ReturnRequested, OrderStatus::Disputed])->count(),
            'blocked_payouts' => AdminAlert::where('type', 'payout_blocked')->whereNull('resolved_at')->count(),
        ];

        $gmvTrend = Order::where('created_at', '>=', now()->subDays(30))
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(total) as total'))
            ->groupBy('date')->orderBy('date')->get();

        // NOTE: the cart is session-backed (Phase 4), so there is no carts table to
        // count top-of-funnel from. Add a persisted cart if that metric is needed.
        $conversionFunnel = [
            'orders_placed' => Order::where('created_at', '>=', now()->subDays(30))->count(),
            'orders_paid' => Order::where('created_at', '>=', now()->subDays(30))->where('status', '!=', 'placed')->count(),
        ];

        $topCategories = OrderItem::join('products', 'order_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->where('order_items.created_at', '>=', now()->subDays(30))
            ->select('categories.name', DB::raw('SUM(order_items.line_total) as revenue'))
            ->groupBy('categories.name')->orderByDesc('revenue')->limit(5)->get();

        return view('admin.dashboard', compact('stats', 'gmvTrend', 'conversionFunnel', 'topCategories'));
    }
}
