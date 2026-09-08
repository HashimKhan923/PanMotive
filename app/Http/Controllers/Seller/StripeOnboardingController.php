<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Services\Payments\StripeConnectService;

class StripeOnboardingController extends Controller
{
    public function return(StripeConnectService $stripe)
    {
        $profile = auth()->user()->sellerProfile;

        if ($profile?->stripe_account_id && $stripe->isOnboardingComplete($profile->stripe_account_id)) {
            $profile->update(['stripe_onboarding_complete' => true]);
        }

        return redirect()->route('seller.onboarding.pending');
    }

    public function refresh(StripeConnectService $stripe)
    {
        // Livewire wizard will re-trigger link generation.
        return redirect()->route('seller.onboarding.start');
    }
}
