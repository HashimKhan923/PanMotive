<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shop pages live on the main domain only. Opened on the seller or admin address (sell.… / admin.…)
 * they forward permanently to the same page on the shop, so search engines never index a second copy
 * of the shop and nobody browses the shop under a portal address.
 */
class ShopOnShopHost
{
    private const SHARED = ['login', 'logout', 'register', 'password.*', 'verification.*', 'profile', 'auth.*', 'two-factor.*', 'impersonate.*'];

    public function handle(Request $request, Closure $next): Response
    {
        $domain = (string) config('app.domain');
        $host = strtolower($request->getHost());

        // Sign-in, sign-out and account pages are shared with the portals, and only page visits move
        // (a form posted on a portal address, like log out, must keep working there).
        $shared = Str::is(self::SHARED, (string) $request->route()?->getName());

        if ($domain !== '' && ! $shared && ($request->isMethod('GET') || $request->isMethod('HEAD'))
            && in_array($host, ['sell.'.$domain, 'admin.'.$domain], true)) {
            $shop = rtrim((string) config('app.url'), '/');
            if (parse_url($shop, PHP_URL_HOST) === $host || ! $shop) {
                $shop = $request->getScheme().'://'.$domain;
            }

            return redirect()->away($shop.$request->getRequestUri(), 301);
        }

        return $next($request);
    }
}
