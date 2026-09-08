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
        $user = $request->user();

        if ($user && $user->requiresTwoFactor() && ! $user->hasTwoFactorEnabled()) {
            return $request->expectsJson()
                ? abort(403, 'Two-factor authentication setup is required for this account.')
                : redirect()->route('two-factor.setup');
        }

        return $next($request);
    }
}
