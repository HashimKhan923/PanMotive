<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Marketing\ReferralService;

class WalletController extends Controller
{
    public function index(ReferralService $referrals)
    {
        $user = auth()->user();
        $transactions = $user->creditTransactions()->latest()->paginate(20);
        $referralCode = $referrals->getOrCreateCode($user);

        return view('storefront.wallet.index', compact('transactions', 'referralCode'));
    }
}
