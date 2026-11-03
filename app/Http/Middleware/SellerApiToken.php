<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SellerApiToken
{
    public function handle(Request $request, Closure $next, ?string $ability = null): Response
    {
        $bearer = $request->bearerToken();

        if (! $bearer) {
            return response()->json(['message' => 'Missing API token.'], 401);
        }

        $token = ApiToken::where('token_hash', hash('sha256', $bearer))->whereNull('revoked_at')->first();

        if (! $token) {
            return response()->json(['message' => 'Invalid or revoked API token.'], 401);
        }

        // A suspended or unapproved store loses API access with its portal access.
        if ($token->seller?->sellerProfile?->status !== 'approved' || $token->seller->is_active === false) {
            return response()->json(['message' => 'This store is not active.'], 403);
        }

        if ($ability && ! $token->can($ability)) {
            return response()->json(['message' => "This token lacks the '{$ability}' scope."], 403);
        }

        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        $request->setUserResolver(fn () => $token->seller);
        $request->attributes->set('api_token', $token);

        return $next($request);
    }
}
