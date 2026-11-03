<?php

namespace App\Livewire\Seller;

use App\Models\SellerProfile;
use App\Services\Payments\StripeConnectService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.seller')]
class OnboardingWizard extends Component
{
    public int $step = 1;

    public string $business_name = '';

    public string $business_type = 'sole_prop';

    public string $ein = '';

    public string $shipping_default_carrier = 'usps';

    public string $shipping_processing_days = '1-2';

    public string $sref = '';

    public function mount(): void
    {
        auth()->user()->assignRole('seller');

        $this->sref = (string) request()->query('sref', '');

        // Resume where the seller left off.
        $profile = auth()->user()->sellerProfile;

        if ($profile) {
            $this->business_name = $profile->business_name ?? '';
            $this->business_type = $profile->business_type ?? 'sole_prop';
            $this->step = $profile->shipping_settings ? 3 : 2;
        }
    }

    public function saveBusinessInfo(): void
    {
        $this->validate([
            'business_name' => 'required|string|max:255',
            'business_type' => 'required|in:sole_prop,llc,corp',
            'ein' => 'nullable|string|max:20',
        ]);

        SellerProfile::updateOrCreate(
            ['user_id' => auth()->id()],
            [
                'business_name' => $this->business_name,
                'business_type' => $this->business_type,
                'ein_encrypted' => $this->ein ?: null,
                'status' => 'pending',
                'kyc_status' => 'unsubmitted',
            ]
        );

        if ($this->sref !== '') {
            app(\App\Services\Seller\SellerReferralService::class)->attach($this->sref, auth()->user());
        }

        $this->step = 2;
    }

    public function saveShippingSettings(): void
    {
        auth()->user()->sellerProfile->update([
            'shipping_settings' => [
                'default_carrier' => $this->shipping_default_carrier,
                'processing_days' => $this->shipping_processing_days,
            ],
        ]);

        $this->step = 3;
    }

    public function connectStripe(StripeConnectService $stripe): void
    {
        $profile = auth()->user()->sellerProfile;

        if (! $profile->stripe_account_id) {
            $accountId = $stripe->createConnectedAccount(auth()->user(), $profile);
            $profile->update(['stripe_account_id' => $accountId]);
        }

        $onboardingUrl = $stripe->generateOnboardingLink($profile->stripe_account_id);

        $this->redirect($onboardingUrl, navigate: false);
    }

    public function render()
    {
        return view('livewire.seller.onboarding-wizard');
    }
}
