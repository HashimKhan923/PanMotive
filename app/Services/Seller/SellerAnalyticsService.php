<?php

namespace App\Services\Seller;

use App\Enums\OrderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Store analytics over a date window, with the same-length previous window for comparison.
 * Only real, kept sales count — unpaid, cancelled and refunded lines are left out.
 */
class SellerAnalyticsService
{
    public static function excluded(): array
    {
        return [OrderStatus::Placed->value, OrderStatus::Cancelled->value, OrderStatus::Refunded->value];
    }

    /** @return array{days:int, from:Carbon, to:Carbon, current:array, previous:array, series:Collection, listings:Collection, categories:Collection, repeat_rate:float, return_rate:float} */
    public function report(int $sellerId, int $days): array
    {
        $to = now();
        $from = now()->subDays($days - 1)->startOfDay();
        $prevFrom = $from->copy()->subDays($days);

        return [
            'days' => $days,
            'from' => $from,
            'to' => $to,
            'current' => $this->totals($sellerId, $from, $to),
            'previous' => $this->totals($sellerId, $prevFrom, $from->copy()->subSecond()),
            'series' => $this->daily($sellerId, $from, $days),
            'listings' => $this->listings($sellerId, $from, $to),
            'categories' => $this->categories($sellerId, $from, $to),
            'repeat_rate' => $this->repeatRate($sellerId),
            'return_rate' => $this->returnRate($sellerId, $from, $to),
        ];
    }

    private function sales(int $sellerId, Carbon $from, Carbon $to)
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.seller_id', $sellerId)
            ->whereNotIn('order_items.status', self::excluded())
            ->where('orders.created_at', '>=', $from)->where('orders.created_at', '<=', $to);
    }

    private function views(int $sellerId, Carbon $from, Carbon $to)
    {
        return DB::table('product_views')
            ->join('products', 'products.id', '=', 'product_views.product_id')
            ->where('products.seller_id', $sellerId)
            ->where('product_views.viewed_at', '>=', $from)->where('product_views.viewed_at', '<=', $to);
    }

    /** @return array{revenue:float, payout:float, orders:int, units:int, aov:float, views:int, visitors:int, conversion:?float} */
    public function totals(int $sellerId, Carbon $from, Carbon $to): array
    {
        $s = $this->sales($sellerId, $from, $to)->selectRaw(
            'COALESCE(SUM(order_items.line_total),0) revenue, COALESCE(SUM(order_items.seller_payout_amount),0) payout,
             COUNT(DISTINCT order_items.order_id) orders, COALESCE(SUM(order_items.quantity),0) units'
        )->first();

        $views = (int) $this->views($sellerId, $from, $to)->count();
        $visitors = (int) $this->views($sellerId, $from, $to)->distinct()->count('product_views.viewer_key');
        $orders = (int) $s->orders;

        return [
            'revenue' => round((float) $s->revenue, 2),
            'payout' => round((float) $s->payout, 2),
            'orders' => $orders,
            'units' => (int) $s->units,
            'aov' => $orders ? round((float) $s->revenue / $orders, 2) : 0.0,
            'views' => $views,
            'visitors' => $visitors,
            // Orders per unique shopper who viewed a listing — the store's real conversion rate.
            'conversion' => $visitors > 0 ? round($orders / $visitors * 100, 2) : null,
        ];
    }

    private function daily(int $sellerId, Carbon $from, int $days): Collection
    {
        $sales = $this->sales($sellerId, $from, now())
            ->get(['orders.created_at', 'order_items.line_total', 'order_items.order_id'])
            ->groupBy(fn ($r) => Carbon::parse($r->created_at)->toDateString());
        $views = $this->views($sellerId, $from, now())->get(['product_views.viewed_at'])
            ->groupBy(fn ($r) => Carbon::parse($r->viewed_at)->toDateString());

        return collect(range($days - 1, 0))->map(function ($d) use ($sales, $views) {
            $date = now()->subDays($d)->toDateString();
            $day = $sales[$date] ?? collect();

            return [
                'date' => $date,
                'total' => round((float) $day->sum('line_total'), 2),
                'orders' => $day->pluck('order_id')->unique()->count(),
                'views' => ($views[$date] ?? collect())->count(),
            ];
        });
    }

    /** Per-listing funnel: views → orders → revenue, including listings that got traffic but no sales. */
    private function listings(int $sellerId, Carbon $from, Carbon $to): Collection
    {
        $sales = $this->sales($sellerId, $from, $to)
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id, COUNT(DISTINCT order_items.order_id) orders, SUM(order_items.quantity) units, SUM(order_items.line_total) revenue')
            ->get()->keyBy('product_id');
        $views = $this->views($sellerId, $from, $to)
            ->groupBy('product_views.product_id')
            ->selectRaw('product_views.product_id, COUNT(*) views, COUNT(DISTINCT product_views.viewer_key) visitors')
            ->get()->keyBy('product_id');

        $ids = $sales->keys()->merge($views->keys())->unique();
        $titles = DB::table('products')->whereIn('id', $ids)->pluck('title', 'id');

        return $ids->map(function ($id) use ($sales, $views, $titles) {
            $s = $sales[$id] ?? null;
            $v = $views[$id] ?? null;
            $visitors = (int) ($v->visitors ?? 0);
            $orders = (int) ($s->orders ?? 0);

            return [
                'product_id' => (int) $id,
                'title' => $titles[$id] ?? 'Deleted listing',
                'views' => (int) ($v->views ?? 0),
                'visitors' => $visitors,
                'orders' => $orders,
                'units' => (int) ($s->units ?? 0),
                'revenue' => round((float) ($s->revenue ?? 0), 2),
                'conversion' => $visitors > 0 ? round($orders / $visitors * 100, 1) : null,
            ];
        })->sortByDesc(fn ($r) => [$r['revenue'], $r['views']])->values();
    }

    private function categories(int $sellerId, Carbon $from, Carbon $to): Collection
    {
        return $this->sales($sellerId, $from, $to)
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->groupBy('categories.name')
            ->selectRaw('categories.name, SUM(order_items.line_total) revenue')
            ->orderByDesc('revenue')->get();
    }

    /** Share of registered customers (guests excluded) who've bought more than once. */
    private function repeatRate(int $sellerId): float
    {
        $buyers = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.seller_id', $sellerId)
            ->whereNotIn('order_items.status', self::excluded())
            ->whereNotNull('orders.buyer_id')
            ->groupBy('orders.buyer_id')
            ->selectRaw('orders.buyer_id, COUNT(DISTINCT orders.id) n')->get();

        return $buyers->count() ? round($buyers->where('n', '>', 1)->count() / $buyers->count() * 100, 1) : 0.0;
    }

    /** Returns opened in the window per delivered line in the window. */
    private function returnRate(int $sellerId, Carbon $from, Carbon $to): float
    {
        $delivered = DB::table('order_items')->where('seller_id', $sellerId)
            ->whereNotNull('delivered_at')->whereBetween('delivered_at', [$from, $to])->count();
        $returns = DB::table('return_requests')
            ->join('order_items', 'order_items.id', '=', 'return_requests.order_item_id')
            ->where('order_items.seller_id', $sellerId)
            ->whereBetween('return_requests.created_at', [$from, $to])->count();

        return $delivered > 0 ? round($returns / $delivered * 100, 1) : 0.0;
    }

    /** Percent change, or null when there's nothing to compare against. */
    public static function change(float $now, float $before): ?float
    {
        return $before > 0 ? round(($now - $before) / $before * 100, 1) : null;
    }
}
