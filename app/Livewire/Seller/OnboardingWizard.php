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
        $user = auth()->user();
        $user->assignRole('seller');

        $this->sref = (string) request()->query('sref', '');

        // The store record exists from the first visit: a pending application admin can see and
        // approve straight away, even before the seller finishes the steps below. Team members
        // (who work in someone else's store) never get one.
        $profile = $user->sellerProfile;
        if (! $profile && ! \App\Models\SellerTeamMember::where('user_id', $user->id)->exists()) {
            $profile = SellerProfile::create([
                'user_id' => $user->id,
                'business_name' => $user->name ?: 'New store',
                'business_type' => 'sole_prop',
                'status' => 'pending',
                'kyc_status' => 'unsubmitted',
                'setup_step' => 0,
            ]);
        }

        // Resume where the seller left off.
        if ($profile) {
            $this->business_name = $profile->setup_step >= 1 ? ($profile->business_name ?? '') : '';
            $this->business_type = $profile->business_type ?? 'sole_prop';
            $this->step = match (true) {
                $profile->setup_step >= 2 => 3,
                $profile->setup_step >= 1 => 2,
                default => 1,
            };
        }
    }

    public function saveBusinessInfo(): void
    {
        $this->validate([
            'business_name' => 'required|string|max:255',
            'business_type' => 'required|in:sole_prop,llc,corp',
            'ein' => 'nullable|string|max:20',
        ]);

        $existing = auth()->user()->sellerProfile;
        $firstTime = ! $existing || (int) $existing->setup_step < 1;

        $profile = SellerProfile::updateOrCreate(
            ['user_id' => auth()->id()],
            [
                'business_name' => $this->business_name,
                'business_type' => $this->business_type,
                'ein_encrypted' => $this->ein ?: null,
                // An application staff already approved stays approved.
                'status' => $existing?->status === 'approved' ? 'approved' : ($existing?->status ?? 'pending'),
                'kyc_status' => $existing?->kyc_status ?? 'unsubmitted',
                'setup_step' => max(1, (int) ($existing?->setup_step ?? 0)),
            ]
        );
        // The store's web address follows the real business name on first setup.
        if ($firstTime && $profile->business_name !== ($existing?->business_name ?? null)) {
            $profile->forceFill(['slug' => SellerProfile::uniqueSlug($profile->business_name, $profile->id)])->save();
        }

        if ($firstTime) {
            try {
                auth()->user()->notify(new \App\Notifications\SellerApplicationReceived($profile->business_name));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if ($this->sref !== '') {
            app(\App\Services\Seller\SellerReferralService::class)->attach($this->sref, auth()->user());
        }

        $this->step = 2;
    }

    public function saveShippingSettings(): void
    {
        $profile = auth()->user()->sellerProfile;
        $profile->update([
            'shipping_settings' => [
                'default_carrier' => $this->shipping_default_carrier,
                'processing_days' => $this->shipping_processing_days,
            ],
            'setup_step' => max(2, (int) $profile->setup_step),
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
