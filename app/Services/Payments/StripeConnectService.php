<?php

namespace App\Services\Payments;

use App\Models\SellerProfile;
use App\Models\User;
use Stripe\StripeClient;

/**
 * Skeleton now — fully wired with webhooks in Phase 4. Needed here because
 * onboarding can't complete without a Connect account to send the seller to.
 */
class StripeConnectService
{
    protected StripeClient $stripe;

    public function __construct()
    {
        $this->stripe = new StripeClient(config('services.stripe.secret') ?: 'sk_test_placeholder');
    }

    public function createConnectedAccount(User $user, SellerProfile $profile): string
    {
        $account = $this->stripe->accounts->create([
            'type' => 'express',
            'country' => 'US',
            'email' => $user->email,
            'business_type' => $profile->business_type === 'sole_prop' ? 'individual' : 'company',
            'capabilities' => [
                'card_payments' => ['requested' => true],
                'transfers' => ['requested' => true],
            ],
        ]);

        return $account->id;
    }

    public function generateOnboardingLink(string $accountId): string
    {
        $link = $this->stripe->accountLinks->create([
            'account' => $accountId,
            'refresh_url' => route('seller.onboarding.stripe.refresh'),
            'return_url' => route('seller.onboarding.stripe.return'),
            'type' => 'account_onboarding',
        ]);

        return $link->url;
    }

    public function isOnboardingComplete(string $accountId): bool
    {
        $account = $this->stripe->accounts->retrieve($accountId);

        return $account->details_submitted && $account->charges_enabled;
    }
}
