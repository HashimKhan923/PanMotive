<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\AffiliateProgram;
use App\Models\Product;
use App\Services\Marketing\AffiliateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class AffiliateLinkController extends Controller
{
    public function go(Request $request, Affiliate $affiliate)
    {
        abort_unless($affiliate->status === 'approved', 404);

        $affiliate->increment('clicks');
        \App\Models\MarketingEvent::click($affiliate->program->seller_id, 'affiliate', $affiliate->id);
        Cookie::queue('pm_aff', $affiliate->code, 60 * 24 * ($affiliate->program->cookie_days ?: 30));

        $product = Product::find($request->integer('p'));
        $profile = $affiliate->program->seller->sellerProfile;

        return redirect()->to($product
            ? route('products.show', $product->slug)
            : ($profile ? route('sellers.show', $profile) : route('home')));
    }

    public function joinForm(\App\Models\SellerProfile $sellerProfile)
    {
        abort_unless($sellerProfile->status === 'approved', 404);
        $program = AffiliateProgram::where('seller_id', $sellerProfile->user_id)->where('is_active', true)->first();
        abort_unless($program, 404);

        return view('storefront.affiliate-join', compact('sellerProfile', 'program'));
    }

    public function join(Request $request, \App\Models\SellerProfile $sellerProfile, AffiliateService $service)
    {
        $program = AffiliateProgram::where('seller_id', $sellerProfile->user_id)->where('is_active', true)->firstOrFail();

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:255',
            'channel' => 'nullable|string|max:120',
        ]);

        $affiliate = $service->join($program, $data['name'], $data['email'], $data['channel'] ?? null, auth()->user());

        return redirect()->route('affiliate.dashboard')->with('success', $affiliate->status === 'approved'
            ? "You're in! Your link is ready below."
            : 'Application submitted — the seller will review it.');
    }

    public function dashboard()
    {
        abort_unless(auth()->check(), 403);

        $affiliates = Affiliate::where('user_id', auth()->id())
            ->with('program.seller.sellerProfile')
            ->withCount('conversions')
            ->get();

        return view('storefront.affiliate-dashboard', compact('affiliates'));
    }
}
