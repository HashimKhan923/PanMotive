<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Services\Seller\SellerAnalyticsService;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    private function days(Request $request): int
    {
        $days = (int) $request->integer('days', 30);

        return in_array($days, [7, 30, 90, 365], true) ? $days : 30;
    }

    public function index(Request $request, SellerAnalyticsService $analytics)
    {
        return view('seller.analytics.index', $analytics->report(auth()->id(), $this->days($request)));
    }

    /** Daily numbers + per-listing funnel for the window, for spreadsheets and accountants. */
    public function export(Request $request, SellerAnalyticsService $analytics)
    {
        $r = $analytics->report(auth()->id(), $this->days($request));

        return response()->streamDownload(function () use ($r) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Revenue', 'Orders', 'Listing views']);
            foreach ($r['series'] as $d) {
                fputcsv($out, [$d['date'], number_format($d['total'], 2, '.', ''), $d['orders'], $d['views']]);
            }
            fputcsv($out, []);
            fputcsv($out, ['Listing', 'Views', 'Unique visitors', 'Orders', 'Units', 'Revenue', 'Conversion %']);
            foreach ($r['listings'] as $l) {
                fputcsv($out, [$l['title'], $l['views'], $l['visitors'], $l['orders'], $l['units'], number_format($l['revenue'], 2, '.', ''), $l['conversion'] ?? '']);
            }
            fclose($out);
        }, 'analytics-'.$r['days'].'d-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }
}
