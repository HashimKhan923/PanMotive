<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Livewire\Seller\CampaignReport;
use App\Models\MarketingCampaign;
use App\Services\Marketing\CampaignRenderer;
use Illuminate\Http\Request;

/** The designed email as a page (builder preview, click map) and the report as a spreadsheet. */
class CampaignEmailController extends Controller
{
    public function preview(Request $request, MarketingCampaign $campaign, CampaignRenderer $renderer)
    {
        abort_unless($campaign->seller_id === auth()->id(), 404);
        $report = $request->boolean('report');
        $html = $renderer->render($campaign, auth()->user(), null, $report ? 'report' : 'preview', $report ? CampaignReport::clickCounts($campaign->id) : [])['html'];

        return response($html)
            ->header('Content-Security-Policy', "default-src 'none'; img-src * data:; style-src 'unsafe-inline'; frame-ancestors 'self'")
            ->header('X-Frame-Options', 'SAMEORIGIN');
    }

    public function export(MarketingCampaign $campaign)
    {
        abort_unless($campaign->seller_id === auth()->id(), 404);

        return response()->streamDownload(function () use ($campaign) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Customer', 'Sent', 'Opened', 'Clicks', 'First click', 'Order value', 'Bounced', 'Problem']);
            $campaign->recipients()->with('user:id,name')->withCount(['clicksLog as human_clicks' => fn ($q) => $q->where('is_bot', false)])
                ->chunkById(500, function ($rows) use ($out) {
                    foreach ($rows as $r) {
                        fputcsv($out, [
                            $r->user?->name, $r->sent_at?->toDateTimeString(), $r->opened_at?->toDateTimeString(), $r->human_clicks,
                            $r->clicked_at?->toDateTimeString(), $r->converted_order_id ? number_format((float) $r->conversion_value, 2, '.', '') : '',
                            $r->bounced_at ? 'yes' : '', $r->failure,
                        ]);
                    }
                });
            fclose($out);
        }, 'campaign-'.$campaign->id.'-report.csv', ['Content-Type' => 'text/csv']);
    }
}
