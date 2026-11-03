<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Services\Seller\SellerScorecardService;

class ScorecardController extends Controller
{
    public function index(SellerScorecardService $scorecard)
    {
        $m = $scorecard->metrics(auth()->user());

        // Keep the stored tier in step when the page is viewed.
        if ($m['tier'] !== $m['stored_tier']) {
            $scorecard->refreshTier(auth()->user());
        }

        $tiers = config('marketplace.seller_tiers');

        return view('seller.scorecard.index', compact('m', 'tiers'));
    }
}
