<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Who a guest shopper is. On the website that's a value in their session; in the shopping app
 * (no session) it's the install's device id, bound by ShopApiContext. Cart, wishlist and recently
 * viewed all key guests by this, so the rules are identical on both.
 */
class ShopContext
{
    public static function isApp(): bool
    {
        return app()->bound('shop.device') || app()->bound('shop.vehicle') || request()->is('api/shop/*');
    }

    /** The guest key for $sessionKey, creating one on the website when $create is true. */
    public static function guestKey(string $sessionKey, bool $create = true): ?string
    {
        if (app()->bound('shop.device')) {
            return (string) app('shop.device');
        }
        if (self::isApp()) {
            return null; // the app didn't say which device: nothing to remember a guest by
        }

        $key = session($sessionKey);
        if (! $key && $create) {
            session([$sessionKey => $key = (string) Str::uuid()]);
        }

        return $key ?: null;
    }

    /** Forget the website's guest key once it has been merged into an account. */
    public static function forgetGuestKey(string $sessionKey): void
    {
        if (! self::isApp()) {
            session()->forget($sessionKey);
        }
    }

    /** The garage vehicle the app sent with this request, if any. */
    public static function appVehicleId(): ?int
    {
        return app()->bound('shop.vehicle') ? (int) app('shop.vehicle') : null;
    }
}
