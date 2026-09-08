<?php

namespace App\Services\Marketing;

use App\Models\Referral;
use App\Models\User;
use Illuminate\Support\Str;

class ReferralService
{
    public function __construct(private WalletService $wallet) {}

    public function getOrCreateCode(User $user): string
    {
        if (! $user->referral_code) {
            $user->update(['referral_code' => strtoupper(Str::random(8))]);
        }

        return $user->referral_code;
    }

    public function trackSignup(string $code, User $newUser): void
    {
        $referrer = User::where('referral_code', strtoupper($code))->first();

        if (! $referrer || $referrer->id === $newUser->id) {
            return;
        }

        if (Referral::where('referred_user_id', $newUser->id)->exists()) {
            return; // one referral per referred user
        }

        $reward = (float) config('marketplace.referral_reward_amount', 10.00);

        Referral::create([
            'referrer_id' => $referrer->id,
            'referred_user_id' => $newUser->id,
            'code' => strtoupper($code),
            'status' => 'pending', // completed once the referred user places their first paid order
            'referrer_reward' => $reward,
            'referred_reward' => $reward,
        ]);
    }

    /** Called from the Stripe webhook once a first order is paid — rewards real purchases only. */
    public function completeIfEligible(User $buyer): void
    {
        $referral = Referral::where('referred_user_id', $buyer->id)->where('status', 'pending')->first();

        if (! $referral) {
            return;
        }

        $isFirstOrder = $buyer->orders()->where('status', '!=', 'cancelled')->count() === 1;

        if (! $isFirstOrder) {
            return;
        }

        $referral->update(['status' => 'completed', 'completed_at' => now()]);

        $this->wallet->credit($referral->referrer, (float) $referral->referrer_reward, 'referral_bonus', 'Referral bonus — friend made their first purchase', $referral);
        $this->wallet->credit($buyer, (float) $referral->referred_reward, 'referral_bonus', 'Welcome bonus from your referral', $referral);
    }
}
