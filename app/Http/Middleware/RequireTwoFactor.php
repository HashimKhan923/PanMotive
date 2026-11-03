<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces privileged roles (seller / admin) to enrol in 2FA before they can
 * reach the portal. The per-login OTP challenge itself lives in the login flow
 * (see the login Volt component + TwoFactorController::challenge).
 */
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        // Local dev convenience: the enrolment gate needs an authenticator app to
        // clear, which gets in the way of working on the portals under
        // `php artisan serve`. Never skipped in testing/staging/production.
        if (app()->environment('local') && ! config('app.force_two_factor', false)) {
            return $next($request);
        }

        // An admin viewing as this user already passed their own 2FA.
        if ($request->session()->has('impersonator_id')) {
            return $next($request);
        }

        $user = $request->user();

        if ($user && $user->requiresTwoFactor() && ! $user->hasTwoFactorEnabled()) {
            return $request->expectsJson()
                ? abort(403, 'Two-factor authentication setup is required for this account.')
                : redirect()->route('two-factor.setup');
        }

        return $next($request);
    }
}
