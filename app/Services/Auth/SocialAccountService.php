<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * "Continue with Google / Apple" for shoppers, on the website and in the app. Signs in the account
 * already linked to that Google/Apple id, or the one with the same provider-verified email, or creates
 * a new shopper account. Staff accounts must use their password.
 */
class SocialAccountService
{
    public const STAFF = ['super_admin', 'support_agent', 'finance', 'catalog_manager'];

    private const COLUMNS = ['google' => 'google_id', 'apple' => 'apple_id'];

    /**
     * @return User|string the account, or a message saying why sign-in was refused
     */
    public function resolve(string $provider, string $subject, ?string $email, bool $emailVerified, ?string $name = null): User|string
    {
        $column = self::COLUMNS[$provider] ?? null;
        if (! $column || $subject === '') {
            return 'Sign-in failed. Please try again.';
        }

        $email = Str::lower(trim((string) $email));
        $user = User::where($column, $subject)->first();

        if (! $user) {
            // Apple can hide the email ("private relay") or leave it out after the first sign-in.
            if ($email === '' || ! $emailVerified) {
                return $provider === 'apple'
                    ? 'Apple didn\'t share an email for this account. Sign in with email instead, or remove Panmotive from your Apple ID settings and try again.'
                    : 'Your Google account has no verified email address.';
            }
            $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        }

        if ($user && $user->hasAnyRole(self::STAFF)) {
            return 'Staff accounts sign in with their email and password.';
        }
        if ($user && $user->is_active === false) {
            return 'This account has been suspended. Contact support if you think this is a mistake.';
        }

        if ($user) {
            // The provider confirmed this email belongs to them: link the account.
            $user->forceFill([$column => $subject, 'email_verified_at' => $user->email_verified_at ?? now()])->save();

            return $user;
        }

        $user = User::create([
            'name' => Str::limit(trim((string) $name) ?: Str::before($email, '@'), 250, ''),
            'email' => $email,
            'password' => Hash::make(Str::random(48)), // they can set one later with "Forgot password"
        ]);
        $user->forceFill([$column => $subject, 'email_verified_at' => now()])->save();
        event(new Registered($user));

        return $user;
    }
}
