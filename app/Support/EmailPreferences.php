<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Which emails a person can switch off. Anything about money, an order, an account or a
 * deadline is always sent; only the "nice to know" emails below have a switch. Guests have
 * no account, so they only ever get order emails and those are always sent.
 */
class EmailPreferences
{
    /** @return array<string, array{label: string, hint: string, seller: bool}> */
    public static function categories(): array
    {
        return [
            'offers' => ['label' => 'Offers', 'hint' => 'When a seller accepts, counters or declines your offer.', 'seller' => false],
            'questions' => ['label' => 'Answers to your questions', 'hint' => 'When a seller answers a question you asked on a listing.', 'seller' => false],
            'review_replies' => ['label' => 'Replies to your reviews', 'hint' => 'When a store replies to a review you wrote.', 'seller' => false],
            'price_drops' => ['label' => 'Price drops', 'hint' => 'When a part on your wishlist gets cheaper.', 'seller' => false],
            'cart_reminders' => ['label' => 'Cart reminders', 'hint' => 'A reminder when you leave parts in your cart.', 'seller' => false],
            'seller_offers' => ['label' => 'Offers from buyers', 'hint' => 'When a buyer makes, accepts or declines an offer on your listings.', 'seller' => true],
            'payouts' => ['label' => 'Payouts', 'hint' => 'Each time money from a delivered order is released to you.', 'seller' => true],
        ];
    }

    /** @return array<string, array{label: string, hint: string, seller: bool}> */
    public static function for(User $user): array
    {
        $isSeller = $user->hasRole('seller') || $user->sellerProfile()->exists();

        return array_filter(self::categories(), fn ($c) => ! $c['seller'] || $isSeller);
    }

    public static function wants(object $notifiable, string $category): bool
    {
        if (! $notifiable instanceof User) {
            return true;
        }

        return ! in_array($category, (array) $notifiable->email_opt_outs, true);
    }

    public static function set(User $user, string $category, bool $on): void
    {
        abort_unless(array_key_exists($category, self::categories()), 404);

        $off = collect((array) $user->email_opt_outs)->reject(fn ($c) => $c === $category);
        if (! $on) {
            $off->push($category);
        }

        $user->forceFill(['email_opt_outs' => $off->unique()->values()->all() ?: null])->save();
    }

    /** A signed link that switches one kind of email off without logging in. */
    public static function stopUrl(User $user, string $category): string
    {
        return URL::signedRoute('email.stop', ['user' => $user->id, 'category' => $category]);
    }
}
