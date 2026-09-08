<?php

use App\Http\Middleware\EnsureSellerApproved;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsSeller;
use App\Http\Middleware\RequireTwoFactor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Csp\AddCspHeaders;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'seller' => EnsureUserIsSeller::class,
            'seller.approved' => EnsureSellerApproved::class,
            'admin' => EnsureUserIsAdmin::class,
            '2fa' => RequireTwoFactor::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'webhooks/stripe',
            'webhooks/easypost',
        ]);

        // Content-Security-Policy on every web response. Off unless CSP_ENABLED=true;
        // then report-only until CSP_ENFORCE=true (see config/csp.php).
        if (env('CSP_ENABLED', false)) {
            $middleware->web(append: [AddCspHeaders::class]);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
