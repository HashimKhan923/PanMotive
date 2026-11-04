<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\SellerProfile;
use App\Services\Marketing\StoreLoyaltyService;
use Illuminate\Http\Request;

class LoyaltyController extends Controller
{
    public function redeem(Request $request, SellerProfile $sellerProfile, StoreLoyaltyService $loyalty)
    {
        $points = (int) $request->integer('points');

        $value = $loyalty->redeemToWallet(auth()->user(), $sellerProfile->user_id, $points);

        return back()->with('success', "Redeemed {$points} points for \${$value} in PanMotive credit.");
    }
}
