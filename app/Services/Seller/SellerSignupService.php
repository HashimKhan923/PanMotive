<?php

namespace App\Services\Seller;

use App\Models\SellerTeamMember;
use App\Models\User;
use App\Notifications\AccountNotice;
use Illuminate\Database\Eloquent\Builder;

/**
 * People who started selling (opened seller sign-up) but never set up their store, so they have no
 * store profile yet — admin sees them in Sellers → "Started sign-up", and they get one reminder.
 */
class SellerSignupService
{
    /** Hours after sign-up before the automatic reminder. */
    public const REMIND_AFTER_HOURS = 24;

    /** Don't chase old sign-ups. */
    public const REMIND_WITHIN_DAYS = 14;

    public function unfinished(): Builder
    {
        return User::role('seller')
            ->whereDoesntHave('sellerProfile')
            ->whereNotIn('id', SellerTeamMember::select('user_id')->whereNotNull('user_id'))
            ->where('is_active', true);
    }

    public function remind(User $user): void
    {
        $user->notify(new AccountNotice(
            'Finish setting up your Panmotive store',
            'You started selling on Panmotive but your store isn\'t set up yet. It takes about 3 minutes: add your business name, shipping basics and how you get paid — then we review your store and you can start listing parts.',
            route('seller.onboarding.start'),
        ));
        $user->forceFill(['seller_signup_reminded_at' => now()])->save();
    }

    /** The automatic reminder: once, a day after sign-up, for sign-ups from the last two weeks. */
    public function remindDue(): int
    {
        $sent = 0;
        $this->unfinished()
            ->whereNull('seller_signup_reminded_at')
            ->where('created_at', '<=', now()->subHours(self::REMIND_AFTER_HOURS))
            ->where('created_at', '>=', now()->subDays(self::REMIND_WITHIN_DAYS))
            ->chunkById(100, function ($users) use (&$sent) {
                foreach ($users as $user) {
                    try {
                        $this->remind($user);
                        $sent++;
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });

        return $sent;
    }
}
