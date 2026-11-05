<?php

namespace App\Http\Middleware;

use App\Support\SellerContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a seller-portal route on a specific ability. The store owner always
 * passes; team members must hold at least one of the abilities listed.
 * Usage: ->middleware('seller.can:payouts.view') or ->middleware('seller.can:orders.view,orders.manage')
 */
class SellerAbility
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        if (! collect($abilities)->contains(fn (string $ability) => SellerContext::can($ability))) {
            abort(403, 'Your role does not allow this. Ask the store owner for access.');
        }

        return $next($request);
    }
}
