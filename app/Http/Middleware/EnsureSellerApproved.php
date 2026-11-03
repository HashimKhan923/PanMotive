<?php

namespace App\Http\Middleware;

use App\Models\SellerTeamMember;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSellerApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $profile = $user->sellerProfile;

        if ($profile && $profile->status === 'approved') {
            return $next($request);
        }

        // Team members ride on the owner's approval status.
        if (! $profile) {
            $onApprovedTeam = SellerTeamMember::where('user_id', $user->id)
                ->where('status', 'active')
                ->whereHas('owner.sellerProfile', fn ($q) => $q->where('status', 'approved'))
                ->exists();

            if ($onApprovedTeam) {
                return $next($request);
            }
        }

        return redirect()->route('seller.onboarding.pending');
    }
}
