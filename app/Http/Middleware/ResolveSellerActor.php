<?php

namespace App\Http\Middleware;

use App\Models\SellerTeamMember;
use App\Support\SellerContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a seller's team member operate the seller portal "as" the store owner.
 *
 * If the logged-in user has no store of their own but is an active team member
 * of an approved store, Auth + the request user resolver are rebound to the
 * owner for the rest of this request, so every existing `auth()->id()` /
 * `->sellerProfile` call in the seller portal transparently scopes to the store.
 * The real human is recorded in SellerContext for audit trails and ability gates.
 */
class ResolveSellerActor
{
    public function handle(Request $request, Closure $next): Response
    {
        SellerContext::clear();

        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // Owner operating their own store — nothing to rebind.
        if ($user->sellerProfile) {
            SellerContext::set($user, $user, null);

            return $next($request);
        }

        $membership = SellerTeamMember::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereHas('owner.sellerProfile', fn ($q) => $q->where('status', 'approved'))
            ->with('owner.sellerProfile')
            ->first();

        if ($membership && $membership->owner) {
            $owner = $membership->owner;

            Auth::setUser($owner);
            $request->setUserResolver(fn () => $owner);
            SellerContext::set($owner, $user, $membership);

            $membership->forceFill(['last_active_at' => now()])->saveQuietly();
        } else {
            SellerContext::set($user, $user, null);
        }

        return $next($request);
    }
}
