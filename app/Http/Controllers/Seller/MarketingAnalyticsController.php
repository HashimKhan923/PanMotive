<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Services\Marketing\MarketingAnalyticsService;
use Illuminate\Http\Request;

class MarketingAnalyticsController extends Controller
{
    public function index(Request $request, MarketingAnalyticsService $analytics)
    {
        $sellerId = auth()->id();
        $days = (int) $request->integer('days', 30);
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        $from = now()->subDays($days - 1)->startOfDay();
        $to = now();

        $sources = $analytics->trafficBySource($sellerId, $from, $to);
        $funnel = $analytics->funnel($sellerId, $from, $to);
        $campaigns = $analytics->campaignAttribution($sellerId);
        $ltv = $analytics->customerLtv($sellerId);
        $cohorts = $analytics->cohortRetention($sellerId);

        return view('seller.marketing.analytics', compact(
            'days', 'sources', 'funnel', 'campaigns', 'ltv', 'cohorts'
        ));
    }
}
