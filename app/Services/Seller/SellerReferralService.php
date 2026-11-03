<?php

namespace App\Services\Seller;

use App\Models\SellerReferral;
use App\Models\User;

class SellerReferralService
{
    public const DISCOUNT_PCT = 2.0;
    public const MONTHS = 3;

    /** The referrer's own shareable link/code (created on demand). */
    public function linkFor(User $referrer): SellerReferral
    {
        return SellerReferral::firstOrCreate(
            ['referrer_id' => $referrer->id, 'referred_id' => null, 'status' => 'pending'],
            ['reward_months' => self::MONTHS, 'commission_discount_pct' => self::DISCOUNT_PCT],
        );
    }

    /** A new seller signed up via a code — attach them to a fresh pending row. */
    public function attach(string $code, User $newSeller): ?SellerReferral
    {
        $template = SellerReferral::where('code', strtoupper($code))->whereNull('referred_id')->first();

        if (! $template || $template->referrer_id === $newSeller->id) {
            return null;
        }

        // Don't double-attach.
        if (SellerReferral::where('referred_id', $newSeller->id)->exists()) {
            return null;
        }

        return SellerReferral::create([
            'referrer_id' => $template->referrer_id,
            'referred_id' => $newSeller->id,
            'code' => strtoupper(\Illuminate\Support\Str::random(8)),
            'status' => 'pending',
            'reward_months' => $template->reward_months,
            'commission_discount_pct' => $template->commission_discount_pct,
        ]);
    }

    /**
     * Called when the referred seller is approved (or lands their first sale).
     * Grants both sides a temporary commission discount.
     */
    public function activateFor(User $referred): void
    {
        $referral = SellerReferral::where('referred_id', $referred->id)->where('status', 'pending')->first();
        if (! $referral) {
            return;
        }

        $ends = now()->addMonths($referral->reward_months);
        $referral->update(['status' => 'active', 'activated_at' => now(), 'ends_at' => $ends]);

        foreach ([$referral->referrer_id, $referral->referred_id] as $userId) {
            User::find($userId)?->sellerProfile?->update([
                'referral_bonus_pct' => $referral->commission_discount_pct,
                'referral_bonus_until' => $ends,
            ]);
        }
    }

    /** Sweep expired bonuses (scheduled). */
    public function sweepExpired(): int
    {
        $count = SellerReferral::where('status', 'active')->where('ends_at', '<=', now())->update(['status' => 'expired']);

        \App\Models\SellerProfile::whereNotNull('referral_bonus_until')
            ->where('referral_bonus_until', '<=', now())
            ->update(['referral_bonus_pct' => null, 'referral_bonus_until' => null]);

        return $count;
    }
}
