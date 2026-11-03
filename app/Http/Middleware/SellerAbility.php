<?php

namespace App\Http\Middleware;

use App\Support\SellerContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a seller-portal route on a specific ability. The store owner always
 * passes; team members must hold the ability. Usage: ->middleware('seller.can:payouts.view')
 */
class SellerAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        if (! SellerContext::can($ability)) {
            abort(403, 'Your role does not allow this. Ask the store owner for access.');
        }

        return $next($request);
    }
}
