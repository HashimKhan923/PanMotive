<?php

namespace App\Services\Membership;

use App\Models\User;
use App\Services\Marketing\WalletService;
use Illuminate\Support\Carbon;

/**
 * PanMotive Plus. Payment here draws down wallet credit; a Stripe subscription
 * is the drop-in replacement for charge() when card billing is wired up.
 */
class MembershipService
{
    public function __construct(private WalletService $wallet) {}

    public function price(): float
    {
        return (float) config('marketplace.plus.price', 59);
    }

    public function termMonths(): int
    {
        return (int) config('marketplace.plus.term_months', 12);
    }

    public function isActive(?User $user): bool
    {
        return (bool) $user?->plus_until && $user->plus_until->isFuture();
    }

    /** Extra cashback rate members earn on top of their loyalty tier. */
    public function bonusCashbackRate(User $user): float
    {
        return $this->isActive($user) ? (float) config('marketplace.plus.cashback_bonus_rate', 2) : 0.0;
    }

    /**
     * Start or extend membership, paying from wallet credit.
     * Extends from the current expiry when already a member (stacking renewals).
     */
    public function join(User $user, bool $autoRenew = true): Carbon
    {
        $this->wallet->debit($user, $this->price(), 'PanMotive Plus membership');

        $from = $this->isActive($user) ? $user->plus_until : now();
        $until = $from->copy()->addMonths($this->termMonths());

        $user->update(['plus_until' => $until, 'plus_auto_renew' => $autoRenew]);

        return $until;
    }

    public function cancelAutoRenew(User $user): void
    {
        $user->update(['plus_auto_renew' => false]);
    }

    /** Renew members whose term lapses within a day and who have credit + auto-renew on. */
    public function renewDue(): int
    {
        $count = 0;

        User::whereNotNull('plus_until')
            ->where('plus_auto_renew', true)
            ->whereBetween('plus_until', [now()->subDay(), now()->addDay()])
            ->chunkById(200, function ($users) use (&$count) {
                foreach ($users as $user) {
                    if ((float) $user->wallet_balance >= $this->price()) {
                        try {
                            $this->join($user, true);
                            $count++;
                        } catch (\Throwable) {
                            // leave it to lapse
                        }
                    }
                }
            });

        return $count;
    }
}
