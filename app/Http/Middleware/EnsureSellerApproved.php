<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSellerApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $profile = $request->user()->sellerProfile;

        if (! $profile || $profile->status !== 'approved') {
            return redirect()->route('seller.onboarding.pending');
        }

        return $next($request);
    }
}
