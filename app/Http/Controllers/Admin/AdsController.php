<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AdminTools;
use App\Http\Controllers\Controller;
use App\Models\AdCampaign;
use App\Models\Boost;
use Illuminate\Http\Request;

/** Seller advertising, for staff to oversee: stop anything that breaks policy. */
class AdsController extends Controller
{
    use AdminTools;

    public function index(Request $request)
    {
        return view('admin.ads.index', [
            'campaigns' => AdCampaign::with('seller.sellerProfile')->withCount('creatives')
                ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', $this->like($request->q)))
                ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 ELSE 2 END")->latest()
                ->paginate(20)->withQueryString(),
            'boosts' => Boost::live()->with('product:id,title', 'seller.sellerProfile')->latest()->take(30)->get(),
            'stats' => [
                'live' => AdCampaign::where('status', 'active')->count(),
                'spend' => (float) AdCampaign::sum('total_spend'),
                'boosts' => Boost::live()->count(),
                'boostFees' => (float) Boost::where('status', '!=', 'cancelled')->sum('fee'),
            ],
        ]);
    }

    public function toggleCampaign(Request $request, AdCampaign $adCampaign)
    {
        abort_unless(in_array($adCampaign->status, ['active', 'paused'], true), 422);
        $data = $request->validate(['reason' => 'required|string|max:255']);

        $adCampaign->update(['status' => $adCampaign->status === 'active' ? 'paused' : 'active']);
        $this->audit($adCampaign->status === 'paused' ? 'Paused ad campaign' : 'Resumed ad campaign', $adCampaign, $data);

        $this->tell($adCampaign->seller, 'Your ad campaign was '.($adCampaign->status === 'paused' ? 'paused' : 'resumed'), "PanMotive changed \"{$adCampaign->name}\". Reason: {$data['reason']}.");

        return back()->with('success', 'Campaign '.($adCampaign->status === 'paused' ? 'paused.' : 'resumed.'));
    }

    public function endBoost(Request $request, Boost $boost)
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);

        $boost->update(['status' => 'ended', 'ends_at' => now()]);
        $this->audit('Ended boost', $boost, $data);

        $this->tell($boost->seller, 'Your boost was ended', 'PanMotive ended a boost on your listing. Reason: '.$data['reason'].'.');

        return back()->with('success', 'Boost ended. The fee is not refunded automatically.');
    }
}
