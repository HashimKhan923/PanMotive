<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\MarketingUnsubscribe;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Marketing\MarketingConsent;
use Illuminate\Http\Request;

/**
 * Unsubscribe from marketing email without logging in. The link is signed, so it only works
 * for the person it was sent to. GET shows a confirmation (link scanners prefetch GETs, so they
 * mustn't unsubscribe anyone); POST unsubscribes — that's also the RFC 8058 one-click target.
 */
class UnsubscribeController extends Controller
{
    public function show(User $user, ?User $seller = null)
    {
        return view('storefront.marketing.unsubscribe', [
            'user' => $user,
            'store' => $this->storeName($seller),
            'seller' => $seller,
            'done' => MarketingUnsubscribe::where('user_id', $user->id)
                ->where(fn ($q) => $q->whereNull('seller_id')->when($seller, fn ($w) => $w->orWhere('seller_id', $seller->id)))
                ->exists(),
        ]);
    }

    public function store(Request $request, MarketingConsent $consent, User $user, ?User $seller = null)
    {
        // Mail-client one-click posts carry no form; the button sends scope=store|all.
        $all = $request->input('scope') === 'all' || ! $seller;
        $consent->unsubscribe($user, $all ? null : $seller->id, $request->has('List-Unsubscribe') ? 'one_click' : 'link');

        if ($request->has('List-Unsubscribe') || $request->expectsJson()) {
            return response()->noContent();
        }

        return view('storefront.marketing.unsubscribe', [
            'user' => $user,
            'store' => $all ? null : $this->storeName($seller),
            'seller' => $seller,
            'done' => true,
            'all' => $all,
        ]);
    }

    private function storeName(?User $seller): ?string
    {
        return $seller ? (SellerProfile::where('user_id', $seller->id)->value('business_name') ?? $seller->name) : null;
    }
}
