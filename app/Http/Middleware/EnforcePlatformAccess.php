<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the admin's account and site switches real on every request: banned accounts are signed
 * out at once, maintenance mode and the checkout / seller sign-up switches apply, and an admin
 * viewing as someone can't spend or change that person's account.
 */
class EnforcePlatformAccess
{
    private const STAFF_ROLES = ['super_admin', 'support_agent', 'finance', 'catalog_manager'];

    /** Reachable during maintenance, or the admin couldn't sign in to turn it off. */
    private const ALWAYS_OPEN = ['login', 'logout', 'two-factor*', 'admin', 'admin/*', 'webhooks/*', 'livewire/*', 'up', 'impersonate/stop'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $impersonating = $request->hasSession() && $request->session()->has('impersonator_id');

        if ($user && $user->is_active === false && ! $impersonating) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['form.email' => 'This account has been suspended.']);
        }

        if (config('marketplace.platform.maintenance') && ! $this->isOpenDuringMaintenance($request)
            && ! ($user && $user->hasAnyRole(self::STAFF_ROLES)) && ! $impersonating) {
            return response()->view('errors.maintenance', ['message' => config('marketplace.platform.maintenance_message')], 503);
        }

        if ($impersonating && $request->isMethodSafe() === false
            && $request->routeIs('checkout.*', 'plus.*', 'password.*', 'profile.*', 'two-factor.*', 'wallet.*', 'subscriptions.*')) {
            return back()->with('error', 'You are viewing as this person. Spending or changing their account is blocked.');
        }

        if ($request->routeIs('checkout.index', 'checkout.store') && ! config('marketplace.platform.checkout_enabled', true)) {
            return redirect()->route('cart.index')->with('error', 'Checkout is paused for a short time. Your cart is saved.');
        }

        if ($request->routeIs('seller.onboarding.start') && ! config('marketplace.platform.seller_signups', true)
            && ! $user?->sellerProfile) {
            return redirect()->route('home')->with('error', 'New seller sign-ups are closed right now. Please check back soon.');
        }

        return $next($request);
    }

    private function isOpenDuringMaintenance(Request $request): bool
    {
        return $request->is(self::ALWAYS_OPEN) || $request->routeIs('admin.*');
    }
}
