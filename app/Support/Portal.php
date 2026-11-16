<?php

namespace App\Support;

/**
 * Which of our three sites the current request is on. The login / sign-up pages are shared,
 * so they use this to show the right words and options.
 */
class Portal
{
    public const SHOP = 'shop';
    public const SELLER = 'seller';
    public const ADMIN = 'admin';

    public static function current(): string
    {
        $domain = (string) config('app.domain');
        $host = request()->getHost();

        return match (true) {
            $domain !== '' && $host === 'admin.'.$domain => self::ADMIN,
            $domain !== '' && $host === 'sell.'.$domain => self::SELLER,
            default => self::SHOP,
        };
    }

    public static function isAdmin(): bool
    {
        return self::current() === self::ADMIN;
    }

    public static function isSeller(): bool
    {
        return self::current() === self::SELLER;
    }

    public static function isShop(): bool
    {
        return self::current() === self::SHOP;
    }
}
