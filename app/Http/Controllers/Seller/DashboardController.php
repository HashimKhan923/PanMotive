<?php

namespace App\Http\Controllers\Seller;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ReturnRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    private const LOW_STOCK = 5;

    public function index()
    {
        $sellerId = auth()->id();
        $profile = auth()->user()->sellerProfile;

        $pendingStatuses = [OrderStatus::Paid->value];
        $escrowStatuses = [OrderStatus::Shipped->value, OrderStatus::OutForDelivery->value, OrderStatus::Delivered->value];

        $stats = [
            'active_listings' => Product::where('seller_id', $sellerId)->where('status', 'active')->count(),
            'pending_orders' => OrderItem::where('seller_id', $sellerId)->where('status', OrderStatus::Paid->value)->count(),
            'to_ship' => OrderItem::where('seller_id', $sellerId)->where('status', OrderStatus::SellerAccepted->value)->count(),
            'lifetime_sales' => (float) OrderItem::where('seller_id', $sellerId)
                ->whereIn('status', [OrderStatus::Delivered->value, OrderStatus::PayoutReleased->value])->sum('line_total'),
            'month_sales' => (float) OrderItem::where('seller_id', $sellerId)
                ->where('created_at', '>=', now()->startOfMonth())
                // Real sales only — not unpaid checkouts, cancellations or refunds.
                ->whereNotIn('status', [OrderStatus::Placed->value, OrderStatus::Cancelled->value, OrderStatus::Refunded->value])
                ->sum('line_total'),
            'pending_payout' => (float) OrderItem::where('seller_id', $sellerId)
                ->whereIn('status', $escrowStatuses)->sum('seller_payout_amount'),
            'released_payout' => (float) OrderItem::where('seller_id', $sellerId)
                ->where('status', OrderStatus::PayoutReleased->value)->sum('seller_payout_amount'),
            'low_stock' => Product::where('seller_id', $sellerId)->where('status', 'active')
                ->where('quantity', '>', 0)->where('quantity', '<=', self::LOW_STOCK)->count(),
            'out_of_stock' => Product::where('seller_id', $sellerId)->where('status', 'active')->where('quantity', '<=', 0)->count(),
            'open_returns' => ReturnRequest::whereIn('status', ['requested', 'approved'])
                ->whereHas('orderItem', fn ($q) => $q->where('seller_id', $sellerId))->count(),
            'unanswered_questions' => \App\Models\Question::where('status', 'published')
                ->whereHas('product', fn ($q) => $q->where('seller_id', $sellerId))
                ->whereDoesntHave('answers', fn ($a) => $a->where('is_seller', true))->count(),
            'late_shipments' => OrderItem::where('seller_id', $sellerId)
                ->whereIn('status', [OrderStatus::Paid->value, OrderStatus::SellerAccepted->value])
                ->with('order:id,placed_at', 'product:id,handling_days,shipping_policy_id,seller_id', 'product.shippingPolicy')
                ->get()->filter(fn (OrderItem $i) => $i->isLate())->count(),
            'support_replies' => \App\Models\SupportTicket::where('seller_id', $sellerId)->where('seller_unread', true)->count(),
            'open_offers' => \App\Models\Offer::where('seller_id', $sellerId)->whereIn('status', ['pending', 'countered'])->count(),
            'rating' => $profile?->rating ? (float) $profile->rating : null,
            'rating_count' => (int) ($profile?->rating_count ?? 0),
        ];

        // 30-day daily sales series, gap-filled for a clean sparkline.
        $raw = OrderItem::where('seller_id', $sellerId)
            ->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->whereNotIn('status', [OrderStatus::Placed->value, OrderStatus::Cancelled->value, OrderStatus::Refunded->value])
            ->select(DB::raw('DATE(created_at) as d'), DB::raw('SUM(line_total) as total'))
            ->groupBy('d')->pluck('total', 'd');

        $series = collect(range(29, 0))->map(function ($daysAgo) use ($raw) {
            $date = now()->subDays($daysAgo)->toDateString();

            return ['date' => $date, 'total' => round((float) ($raw[$date] ?? 0), 2)];
        });

        $topProducts = Product::where('seller_id', $sellerId)
            ->withCount('orderItems')
            ->orderByDesc('order_items_count')
            ->take(5)->get();

        $recentOrders = OrderItem::where('seller_id', $sellerId)
            ->with('order:id,order_number,buyer_id', 'product:id,slug')
            ->latest()->take(6)->get();

        $lowStockProducts = Product::where('seller_id', $sellerId)->where('status', 'active')
            ->where('quantity', '<=', self::LOW_STOCK)
            ->orderBy('quantity')->take(5)->get();

        $payoutReady = $profile && $profile->stripe_account_id && $profile->stripe_onboarding_complete;

        $health = app(\App\Services\Seller\SellerScorecardService::class)->healthLights(auth()->user());

        return view('seller.dashboard', compact(
            'stats', 'series', 'topProducts', 'recentOrders', 'lowStockProducts', 'payoutReady', 'health'
        ));
    }
}
