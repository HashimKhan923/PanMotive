<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OffsiteAdAttribution;
use App\Models\OffsiteAdClick;
use App\Models\OffsiteAdSpend;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Admin view of Offsite Ads: feed URLs for the ad platforms, and whether the program pays for itself. */
class OffsiteAdsController extends Controller
{
    public function index()
    {
        $channels = config('marketplace.offsite_ads.channels', []);
        $key = config('marketplace.offsite_ads.feed_key');

        // Last 6 months: clicks, orders, fees actually collected (lines not cancelled/refunded) and our spend.
        $months = collect(range(5, 0))->map(function ($ago) {
            $start = now()->startOfMonth()->subMonths($ago);
            $end = $start->copy()->endOfMonth();

            $fees = (float) OrderItem::where('offsite_ad_fee', '>', 0)
                ->whereNotIn('status', ['cancelled', 'refunded'])
                ->whereBetween('created_at', [$start, $end])->sum('offsite_ad_fee');
            $spend = (float) OffsiteAdSpend::whereDate('month', $start->toDateString())->sum('amount');

            return [
                'label' => $start->format('M Y'),
                'clicks' => OffsiteAdClick::whereBetween('created_at', [$start, $end])->count(),
                'orders' => OffsiteAdAttribution::whereBetween('created_at', [$start, $end])->count(),
                'sales' => (float) OffsiteAdAttribution::whereBetween('created_at', [$start, $end])->sum('attributed_sales'),
                'fees' => $fees,
                'spend' => $spend,
                'profit' => $fees - $spend,
            ];
        });

        $topSellers = OffsiteAdAttribution::query()
            ->select('seller_id', DB::raw('count(*) as orders'), DB::raw('sum(attributed_sales) as sales'), DB::raw('sum(fee_amount) as fees'))
            ->groupBy('seller_id')->orderByDesc('fees')->with('seller.sellerProfile')->take(10)->get();

        return view('admin.offsite-ads.index', [
            'channels' => $channels,
            'feeds' => collect($channels)->map(fn ($label, $code) => route('feeds.offsite', array_filter(['channel' => $code, 'key' => $key]))),
            'months' => $months,
            'totals' => [
                'fees' => $months->sum('fees'),
                'spend' => $months->sum('spend'),
                'sales' => $months->sum('sales'),
            ],
            'spendRows' => OffsiteAdSpend::orderByDesc('month')->orderBy('channel')->take(24)->get(),
            'topSellers' => $topSellers,
            'enabled' => (bool) config('marketplace.offsite_ads.enabled'),
        ]);
    }

    /** Record what we actually paid an ad platform for a month (from its billing page). */
    public function storeSpend(Request $request)
    {
        $data = $request->validate([
            'month' => 'required|date_format:Y-m',
            'channel' => 'required|in:'.implode(',', array_keys(config('marketplace.offsite_ads.channels', []))),
            'amount' => 'required|numeric|min:0|max:10000000',
            'note' => 'nullable|string|max:255',
        ]);

        $month = Carbon::createFromFormat('Y-m', $data['month'])->startOfMonth();

        // Match on the date part only: a date column can come back as "2026-10-01 00:00:00"
        // depending on the driver, which a plain where('month', '2026-10-01') would miss.
        $row = OffsiteAdSpend::whereDate('month', $month->toDateString())->where('channel', $data['channel'])->first()
            ?? new OffsiteAdSpend(['month' => $month, 'channel' => $data['channel']]);
        $row->fill(['amount' => $data['amount'], 'note' => $data['note'] ?? null])->save();

        return back()->with('success', 'Ad spend saved.');
    }
}
