<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\PromoLink;
use Illuminate\Support\Facades\Cookie;

class PromoLinkController extends Controller
{
    public function go(PromoLink $promoLink)
    {
        $promoLink->increment('clicks');
        \App\Models\MarketingEvent::click($promoLink->seller_id, $promoLink->channel(), $promoLink->id);

        // 30-day attribution cookie for the storefront checkout to read.
        Cookie::queue('pm_ref', $promoLink->code, 60 * 24 * 30);

        return redirect()->to($promoLink->destination());
    }
}
