<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\MarketplaceInsights;
use Illuminate\Http\Request;

class InsightsController extends Controller
{
    public function index(Request $request, MarketplaceInsights $insights)
    {
        $days = in_array((int) $request->query('days'), [7, 30, 90, 365], true) ? (int) $request->query('days') : 90;
        $from = now()->subDays($days - 1)->startOfDay();

        return view('admin.insights.index', [
            'days' => $days,
            'health' => $insights->health($from),
            'funnel' => $insights->funnel($from),
            'loyalty' => $insights->loyalty($from),
            'patterns' => $insights->patterns($from),
            'topProducts' => $insights->topProducts($from),
            'concentration' => $insights->concentration($from),
            'issues' => $insights->catalogIssues(),
        ]);
    }
}
