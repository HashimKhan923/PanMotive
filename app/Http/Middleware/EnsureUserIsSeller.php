<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsSeller
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasRole('seller')) {
            return redirect()->route('seller.onboarding.start');
        }

        if ($user->sellerProfile?->status === 'suspended') {
            abort(403, 'Your seller account has been suspended. Contact support.');
        }

        return $next($request);
    }
}
