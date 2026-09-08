<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    protected array $adminRoles = ['super_admin', 'support_agent', 'finance', 'catalog_manager'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasAnyRole($this->adminRoles)) {
            abort(403);
        }

        return $next($request);
    }
}
