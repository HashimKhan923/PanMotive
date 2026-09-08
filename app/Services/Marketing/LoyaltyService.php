<?php

namespace App\Services\Marketing;

use App\Models\User;
use App\Notifications\LoyaltyTierUpgraded;

class LoyaltyService
{
    public function recalculateTier(User $user): void
    {
        $tiers = config('marketplace.loyalty_tiers');
        $newTier = 'bronze';

        foreach ($tiers as $tierName => $config) {
            if ((float) $user->lifetime_spend >= $config['min_spend']) {
                $newTier = $tierName;
            }
        }

        if ($newTier !== $user->loyalty_tier) {
            $user->update(['loyalty_tier' => $newTier]);
            $user->notify(new LoyaltyTierUpgraded($newTier));
        }
    }

    public function cashbackRate(User $user): float
    {
        return (float) config("marketplace.loyalty_tiers.{$user->loyalty_tier}.cashback_rate", 1.0);
    }

    public function freeShippingThreshold(User $user): float
    {
        return (float) config("marketplace.loyalty_tiers.{$user->loyalty_tier}.free_shipping_threshold", 75);
    }
}
