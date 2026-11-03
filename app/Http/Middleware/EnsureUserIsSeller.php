<?php

namespace App\Http\Middleware;

use App\Models\SellerTeamMember;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsSeller
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('seller.onboarding.start');
        }

        if ($user->hasRole('seller')) {
            if ($user->sellerProfile?->status === 'suspended') {
                abort(403, 'Your seller account has been suspended. Contact support.');
            }

            return $next($request);
        }

        // A team member of an approved store may enter the portal too.
        $isTeamMember = SellerTeamMember::where('user_id', $user->id)
            ->where('status', 'active')
            ->whereHas('owner.sellerProfile', fn ($q) => $q->whereNotIn('status', ['suspended']))
            ->exists();

        if ($isTeamMember) {
            return $next($request);
        }

        return redirect()->route('seller.onboarding.start');
    }
}
