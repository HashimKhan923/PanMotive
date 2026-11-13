<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Services\Marketing\Social\SocialToolkit;

/**
 * A store's "link in bio" page (panmotive…/@store): the one link sellers put on Instagram and
 * TikTok. Featured parts, deals and a coupon, every tap going through a tracked link.
 */
class LinkInBioController extends Controller
{
    public function show(string $slug)
    {
        $sp = SellerProfile::where('slug', $slug)->firstOrFail();
        abort_if(in_array($sp->status, ['suspended', 'rejected'], true) || $sp->user?->is_active === false, 404);
        $bio = SocialToolkit::bio($sp);
        abort_unless($bio['enabled'], 404);

        // Count real visits (one per browser per hour), not every refresh.
        $key = 'bio_seen_'.$sp->id;
        if (! session()->has($key) || now()->diffInMinutes(session($key)) > 60) {
            session([$key => now()]);
            $sp->increment('bio_views');
        }

        $base = fn () => Product::listed()->where('seller_id', $sp->user_id)->where('quantity', '>', 0)->with('media');
        $featured = $bio['product_ids'] ? $base()->whereIn('id', $bio['product_ids'])->get()->sortBy(fn ($p) => array_search($p->id, $bio['product_ids']))->values() : collect();
        if ($featured->isEmpty()) {
            $featured = $base()->latest('id')->take(6)->get();
        }
        $deals = $bio['show_deals'] ? $base()->whereNotIn('id', $featured->pluck('id'))->get()->filter(fn ($p) => $p->effectivePrice() < (float) $p->price)->take(4)->values() : collect();
        $coupon = $bio['coupon_id'] ? Coupon::where('seller_id', $sp->user_id)->whereNull('user_id')->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->find($bio['coupon_id']) : null;

        $link = fn (?Product $p) => route('promo.go', SocialToolkit::bioLink($sp, $p?->id));

        return view('storefront.link-in-bio', [
            'sp' => $sp, 'bio' => $bio, 'featured' => $featured, 'deals' => $deals, 'coupon' => $coupon, 'link' => $link,
            'logo' => $sp->logo_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($sp->logo_path) : null,
            'color' => \App\Services\Marketing\CampaignDesign::brand($sp->email_brand)['color'],
        ]);
    }
}
