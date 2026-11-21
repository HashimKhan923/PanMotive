<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets up every request from the Panmotive shopping app (routes/api.php, /api/shop/v1).
 *
 * The app has no cookies or session, so it says who it is with headers:
 *  - Authorization: Bearer <token>  → the signed-in shopper (optional on most routes).
 *  - X-Device-Id: <uuid>            → this install. Guests' cart, wishlist and recently viewed hang off it.
 *  - X-Vehicle-Id: <id>             → the vehicle in the shopper's garage, for "fits your vehicle".
 *
 * The cart / wishlist / garage services read these through the container (see ShopContext), so the
 * website and the app share one set of rules. Also applies the admin's switches (bans, maintenance).
 */
class ShopApiContext
{
    private const STAFF_ROLES = ['super_admin', 'support_agent', 'finance', 'catalog_manager'];

    public function handle(Request $request, Closure $next): Response
    {
        // Start from nobody: the shopper is only who this request's token says (matters for long-lived
        // workers and tests, where the auth manager outlives a single request).
        config(['auth.defaults.guard' => 'web']);
        Auth::forgetGuards();

        // A token, when sent, must be valid — an expired one should make the app sign in again,
        // not quietly turn the shopper into a guest.
        if ($bearer = $request->bearerToken()) {
            $token = PersonalAccessToken::findToken($bearer);
            $user = $token?->tokenable;
            if (! $token || ! $user || ($token->expires_at && $token->expires_at->isPast())) {
                return response()->json(['message' => 'Your session has ended. Please sign in again.', 'code' => 'unauthenticated'], 401);
            }
            if ($user->is_active === false) {
                $token->delete();

                return response()->json(['message' => 'This account has been suspended.', 'code' => 'suspended'], 403);
            }
            $user->withAccessToken($token);
            Auth::shouldUse('sanctum');
            Auth::guard('sanctum')->setUser($user);
            Auth::setUser($user);
            // Note when the device was last used, at most once an hour.
            if (! $token->last_used_at || $token->last_used_at->lt(now()->subHour())) {
                $token->forceFill(['last_used_at' => now()])->save();
            }
        }

        $device = (string) $request->header('X-Device-Id', '');
        if ($device !== '' && preg_match('/^[A-Za-z0-9-]{16,64}$/', $device)) {
            app()->instance('shop.device', 'app-'.$device);
        }

        $vehicle = $request->header('X-Vehicle-Id');
        if ($vehicle !== null && ctype_digit((string) $vehicle)) {
            app()->instance('shop.vehicle', (int) $vehicle);
        }

        $user = $request->user();
        if (config('marketplace.platform.maintenance') && ! ($user && $user->hasAnyRole(self::STAFF_ROLES))) {
            return response()->json([
                'message' => config('marketplace.platform.maintenance_message') ?: "We're making some improvements. Please check back soon.",
                'code' => 'maintenance',
            ], 503);
        }

        return $next($request);
    }
}
