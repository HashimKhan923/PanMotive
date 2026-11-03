<?php

namespace App\Services\Marketing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MarketingAnalyticsService
{
    private const REAL_ITEM_STATUSES_EXCLUDED = ['placed', 'cancelled', 'refunded'];

    /**
     * Clicks, attributed orders, revenue and cost for each marketing channel in the window, read from
     * the dated marketing_events log (so 7 / 30 / 90 days really means that), plus "Organic / direct"
     * for everything no channel touched. An order touched by two channels shows in both rows, but is
     * only subtracted once from organic.
     *
     * @return array<int, array{source:string, label:string, clicks:int, orders:int, revenue:float, conv:float, cost:float, roas:?float}>
     */
    public function trafficBySource(int $sellerId, Carbon $from, Carbon $to): array
    {
        $events = DB::table('marketing_events')
            ->where('seller_id', $sellerId)
            ->whereBetween('created_at', [$from, $to])
            ->get(['channel', 'kind', 'order_id', 'revenue']);

        $costs = $this->costs($sellerId, $from, $to);

        $rows = [];
        foreach (\App\Models\MarketingEvent::CHANNELS as $channel => $label) {
            $mine = $events->where('channel', $channel);
            $conversions = $mine->where('kind', 'conversion');
            $row = $this->row($channel, $label,
                $mine->where('kind', 'click')->count(),
                $conversions->pluck('order_id')->filter()->unique()->count(),
                round((float) $conversions->sum('revenue'), 2));
            $row['cost'] = round($costs[$channel] ?? 0, 2);
            $row['roas'] = $row['cost'] > 0 ? round($row['revenue'] / $row['cost'], 2) : null;
            $rows[] = $row;
        }

        // Organic / direct = the seller's real sales in the window that no channel can claim.
        $attributedOrderIds = $events->where('kind', 'conversion')->pluck('order_id')->filter()->unique();
        $sales = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.seller_id', $sellerId)
            ->whereNotIn('order_items.status', self::REAL_ITEM_STATUSES_EXCLUDED)
            ->whereBetween('orders.created_at', [$from, $to])
            ->groupBy('orders.id')
            ->selectRaw('orders.id, SUM(order_items.line_total) as r')
            ->get();
        $organic = $sales->reject(fn ($o) => $attributedOrderIds->contains($o->id));
        $row = $this->row('organic', 'Organic / direct', 0, $organic->count(), round((float) $organic->sum('r'), 2));
        $row['cost'] = 0.0;
        $row['roas'] = null;
        $rows[] = $row;

        usort($rows, fn ($a, $b) => [$b['revenue'], $b['clicks']] <=> [$a['revenue'], $a['clicks']]);

        return $rows;
    }

    /** What each paid channel cost the seller in the window. @return array<string, float> */
    public function costs(int $sellerId, Carbon $from, Carbon $to): array
    {
        return [
            // Ad credit spent on boosts and sponsored clicks, net of refunds.
            'ads' => -(float) DB::table('ad_transactions')->where('seller_id', $sellerId)
                ->whereIn('type', ['boost_spend', 'refund'])
                ->whereBetween('created_at', [$from, $to])->sum('amount'),
            'affiliate' => (float) DB::table('affiliate_conversions')
                ->join('affiliates', 'affiliates.id', '=', 'affiliate_conversions.affiliate_id')
                ->join('affiliate_programs', 'affiliate_programs.id', '=', 'affiliates.affiliate_program_id')
                ->where('affiliate_programs.seller_id', $sellerId)
                ->where('affiliate_conversions.status', '!=', 'reversed')
                ->whereBetween('affiliate_conversions.created_at', [$from, $to])->sum('affiliate_conversions.commission'),
            'offsite_ads' => (float) DB::table('offsite_ad_attributions')->where('seller_id', $sellerId)
                ->whereBetween('created_at', [$from, $to])->sum('fee_amount'),
        ];
    }

    /** @return array{visits:int, orders:int, revenue:float, aov:float, conv:float, attributed_share:float} */
    public function funnel(int $sellerId, Carbon $from, Carbon $to): array
    {
        $sources = $this->trafficBySource($sellerId, $from, $to);
        $visits = array_sum(array_column($sources, 'clicks'));

        $totals = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.seller_id', $sellerId)
            ->whereNotIn('order_items.status', self::REAL_ITEM_STATUSES_EXCLUDED)
            ->whereBetween('orders.created_at', [$from, $to])
            ->selectRaw('COUNT(DISTINCT orders.id) o, COALESCE(SUM(order_items.line_total),0) r')->first();
        $orders = (int) $totals->o;
        $revenue = round((float) $totals->r, 2);
        $organic = collect($sources)->firstWhere('source', 'organic');
        $marketingOrders = $orders - (int) ($organic['orders'] ?? 0);

        return [
            'visits' => $visits,
            'orders' => $orders,
            'revenue' => $revenue,
            'aov' => $orders > 0 ? round($revenue / $orders, 2) : 0.0,
            // Conversion only makes sense for orders that came from a tracked click.
            'conv' => $visits > 0 ? round($marketingOrders / $visits * 100, 2) : 0.0,
            'attributed_share' => $orders > 0 ? round($marketingOrders / $orders * 100, 1) : 0.0,
        ];
    }

    /**
     * Per-campaign attribution table.
     *
     * @return array<int, array{name:string, channel:string, recipients:int, opened:int, clicked:int, orders:int, revenue:float}>
     */
    public function campaignAttribution(int $sellerId): array
    {
        return DB::table('marketing_campaigns')
            ->where('seller_id', $sellerId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(function ($c) {
                $stats = json_decode($c->stats ?? '{}', true) ?: [];
                $revenue = (float) ($stats['revenue'] ?? 0);

                return [
                    'name' => $c->name,
                    'channel' => $c->channel,
                    'recipients' => (int) ($stats['recipients'] ?? 0),
                    'opened' => (int) ($stats['opened'] ?? 0),
                    'clicked' => (int) ($stats['clicked'] ?? 0),
                    'orders' => (int) ($stats['converted'] ?? 0),
                    'revenue' => round($revenue, 2),
                ];
            })->all();
    }

    /** @return array{customers:int, avg_ltv:float, repeat_rate:float, top:array<int, array{name:string, orders:int, spent:float}>} */
    public function customerLtv(int $sellerId): array
    {
        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('users', 'users.id', '=', 'orders.buyer_id')
            ->where('order_items.seller_id', $sellerId)
            ->whereNotIn('order_items.status', self::REAL_ITEM_STATUSES_EXCLUDED)
            ->groupBy('orders.buyer_id', 'users.name')
            ->select('users.name', DB::raw('COUNT(DISTINCT orders.id) as orders'), DB::raw('SUM(order_items.line_total) as spent'))
            ->get();

        $customers = $rows->count();
        $totalSpent = (float) $rows->sum('spent');
        $repeat = $rows->where('orders', '>', 1)->count();

        return [
            'customers' => $customers,
            'avg_ltv' => $customers > 0 ? round($totalSpent / $customers, 2) : 0.0,
            'repeat_rate' => $customers > 0 ? round($repeat / $customers * 100, 1) : 0.0,
            'top' => $rows->sortByDesc('spent')->take(10)->map(fn ($r) => [
                'name' => $r->name,
                'orders' => (int) $r->orders,
                'spent' => round((float) $r->spent, 2),
            ])->values()->all(),
        ];
    }

    /**
     * Month-cohort retention: for buyers whose first order from this seller was in month M,
     * what share ordered again in each subsequent month (0..5 offsets).
     *
     * @return array<int, array{cohort:string, size:int, retention:array<int,float>}>
     */
    public function cohortRetention(int $sellerId): array
    {
        $orders = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.seller_id', $sellerId)
            ->whereNotIn('order_items.status', self::REAL_ITEM_STATUSES_EXCLUDED)
            ->whereNotNull('orders.buyer_id')
            ->where('orders.created_at', '>=', now()->subMonths(6)->startOfMonth())
            ->get(['orders.buyer_id', 'orders.created_at'])
            ->groupBy('buyer_id');

        $cohorts = [];
        foreach ($orders as $buyerId => $buyerOrders) {
            $months = $buyerOrders->map(fn ($o) => Carbon::parse($o->created_at)->startOfMonth())->sort()->values();
            $first = $months->first();
            $key = $first->format('Y-m');
            $cohorts[$key] ??= ['size' => 0, 'buckets' => array_fill(0, 6, 0)];
            $cohorts[$key]['size']++;

            $seen = [];
            foreach ($months as $m) {
                $offset = $first->diffInMonths($m);
                if ($offset >= 0 && $offset < 6 && ! isset($seen[$offset])) {
                    $cohorts[$key]['buckets'][$offset]++;
                    $seen[$offset] = true;
                }
            }
        }

        ksort($cohorts);

        return collect($cohorts)->map(fn ($data, $key) => [
            'cohort' => $key,
            'size' => $data['size'],
            'retention' => array_map(
                fn ($n) => $data['size'] > 0 ? round($n / $data['size'] * 100, 0) : 0.0,
                $data['buckets']
            ),
        ])->values()->all();
    }

    private function row(string $source, string $label, int $clicks, int $orders, float $revenue): array
    {
        return [
            'source' => $source,
            'label' => $label,
            'clicks' => $clicks,
            'orders' => $orders,
            'revenue' => round($revenue, 2),
            'conv' => $clicks > 0 ? round($orders / $clicks * 100, 2) : 0.0,
        ];
    }
}
