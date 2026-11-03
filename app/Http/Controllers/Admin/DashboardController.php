<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Support\AdminNav;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

class DashboardController extends Controller
{
    /** Orders that took real money: "placed" hasn't been paid and "cancelled" never counted. */
    private const UNPAID_ORDER = ['placed', 'cancelled'];

    private const UNEARNED_LINE = ['placed', 'cancelled', 'refunded'];

    public function index(Request $request)
    {
        $user = $request->user();
        $days = in_array((int) $request->query('days'), [7, 30, 90, 365], true) ? (int) $request->query('days') : 30;
        $from = now()->subDays($days - 1)->startOfDay();
        $prevFrom = (clone $from)->subDays($days);

        $orders = fn (Carbon $a, Carbon $b) => Order::whereBetween('created_at', [$a, $b])->whereNotIn('status', self::UNPAID_ORDER);

        $sales = (float) $orders($from, now())->sum(DB::raw('total + credits_used'));
        $prevSales = (float) $orders($prevFrom, (clone $from)->subSecond())->sum(DB::raw('total + credits_used'));
        $count = $orders($from, now())->count();
        $prevCount = $orders($prevFrom, (clone $from)->subSecond())->count();
        $commission = $this->commission($from, now());
        $prevCommission = $this->commission($prevFrom, (clone $from)->subSecond());
        $refunds = (float) OrderItem::where('status', OrderStatus::Refunded)->whereBetween('updated_at', [$from, now()])->sum('line_total');

        $newBuyers = User::whereBetween('created_at', [$from, now()])->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', ['seller', 'super_admin', 'support_agent', 'finance', 'catalog_manager']))->count();
        $prevNewBuyers = User::whereBetween('created_at', [$prevFrom, (clone $from)->subSecond()])->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', ['seller', 'super_admin', 'support_agent', 'finance', 'catalog_manager']))->count();

        $stats = [
            'sales' => $sales, 'sales_delta' => $this->delta($sales, $prevSales),
            'commission' => $commission, 'commission_delta' => $this->delta($commission, $prevCommission),
            'orders' => $count, 'orders_delta' => $this->delta($count, $prevCount),
            'aov' => $count ? $sales / $count : 0,
            'refunds' => $refunds,
            'new_buyers' => $newBuyers, 'new_buyers_delta' => $this->delta($newBuyers, $prevNewBuyers),
            'active_sellers' => SellerProfile::where('status', 'approved')->count(),
            'live_listings' => Product::listed()->count(),
        ];

        return view('admin.dashboard', [
            'days' => $days,
            'stats' => $stats,
            'canFinance' => $user->can('finance.view'),
            'trend' => $this->trend($from, $days),
            'previousTrend' => $this->trend($prevFrom, $days),
            'statusBreakdown' => $this->statusBreakdown($from),
            'topCategories' => $this->topCategories($from),
            'topSellers' => $this->topSellers($from),
            'queue' => $this->workQueue($user),
            'health' => app(\App\Services\MarketplaceInsights::class)->health($from),
            'recentOrders' => Order::with('buyer:id,name')->latest()->take(6)->get(),
            'activity' => $user->can('audit.view') ? Activity::with('causer')->latest()->take(7)->get() : collect(),
        ]);
    }

    private function commission(Carbon $from, Carbon $to): float
    {
        return (float) OrderItem::whereBetween('created_at', [$from, $to])->whereNotIn('status', self::UNEARNED_LINE)->sum('platform_fee_amount');
    }

    private function delta(float $now, float $before): ?float
    {
        return $before > 0 ? round(($now - $before) / $before * 100, 1) : null;
    }

    /** @return array{labels: list<string>, sales: list<float>} one point per day, with empty days filled in */
    private function trend(Carbon $from, int $days): array
    {
        $to = (clone $from)->addDays($days)->subSecond();
        $byDay = Order::whereBetween('created_at', [$from, $to])->whereNotIn('status', self::UNPAID_ORDER)
            ->selectRaw('DATE(created_at) AS day, SUM(total + credits_used) AS sales')
            ->groupBy('day')->pluck('sales', 'day');

        $labels = [];
        $sales = [];
        foreach (range(0, $days - 1) as $i) {
            $date = (clone $from)->addDays($i);
            $labels[] = $date->format('M j');
            $sales[] = round((float) ($byDay[$date->toDateString()] ?? 0), 2);
        }

        return compact('labels', 'sales');
    }

    private function statusBreakdown(Carbon $from): array
    {
        return OrderItem::where('created_at', '>=', $from)
            ->selectRaw('status, COUNT(*) AS c')->groupBy('status')->orderByDesc('c')->get()
            ->map(fn ($row) => ['label' => $row->status->label(), 'value' => (int) $row->c])->all();
    }

    private function topCategories(Carbon $from): array
    {
        return OrderItem::join('products', 'order_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->leftJoin('categories AS parents', 'categories.parent_id', '=', 'parents.id')
            ->where('order_items.created_at', '>=', $from)
            ->whereNotIn('order_items.status', self::UNEARNED_LINE)
            ->selectRaw('COALESCE(parents.name, categories.name) AS root_name, SUM(order_items.line_total) AS revenue')
            ->groupBy('root_name')->orderByDesc('revenue')->limit(5)->get()
            ->map(fn ($row) => ['label' => $row->root_name, 'value' => (float) $row->revenue])->all();
    }

    private function topSellers(Carbon $from): array
    {
        return OrderItem::where('order_items.created_at', '>=', $from)
            ->whereNotIn('order_items.status', self::UNEARNED_LINE)
            ->join('seller_profiles', 'seller_profiles.user_id', '=', 'order_items.seller_id')
            ->selectRaw('seller_profiles.business_name AS name, seller_profiles.slug AS slug, SUM(order_items.line_total) AS revenue')
            ->groupBy('name', 'slug')->orderByDesc('revenue')->limit(5)->get()
            ->map(fn ($row) => [
                'label' => $row->name, 'value' => (float) $row->revenue,
                'href' => auth()->user()->can('sellers.approve') ? route('admin.sellers.show', $row->slug) : null,
            ])->all();
    }

    /** Everything waiting on a person, most urgent first, limited to what this staff member can act on. */
    private function workQueue(User $user): array
    {
        $c = AdminNav::counts();

        return collect([
            ['Escalated claims', $c['claims'], 'alert', 'admin.disputes.index', 'orders.view', 'A buyer is waiting on Pan Motive to decide.'],
            ['Escalated returns', $c['escalated_returns'], 'refresh', 'admin.returns.index', 'orders.view', 'The seller and buyer could not agree.'],
            ['Open alerts', $c['alerts'], 'bell', 'admin.alerts.index', null, 'Blocked payouts and system problems.'],
            ['Seller applications', $c['pending_sellers'], 'store', 'admin.sellers.index', 'sellers.approve', 'New stores waiting for approval.'],
            ['Identity checks', $c['kyc'], 'shield', 'admin.verifications.index', 'sellers.approve', 'Documents waiting for review.'],
            ['Policy appeals', $c['appeals'], 'shield', 'admin.appeals.index', 'sellers.approve', 'Sellers disputing a violation.'],
            ['Listings to review', $c['pending_listings'], 'box', 'admin.products.index', 'catalog.manage', 'New listings waiting to go live.'],
            ['Reported reviews', $c['reported_reviews'], 'star', 'admin.reviews.index', 'reviews.moderate', 'Sellers reported these reviews.'],
            ['Support tickets', $c['tickets'], 'message', 'admin.support.index', null, 'Unread seller messages.'],
        ])->filter(fn ($row) => $row[1] > 0 && ($row[4] === null || $user->can($row[4])))
            ->map(fn ($row) => ['label' => $row[0], 'count' => $row[1], 'icon' => $row[2], 'url' => route($row[3]), 'hint' => $row[5]])
            ->values()->all();
    }
}
