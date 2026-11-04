<?php

use App\Http\Middleware\EnforcePlatformAccess;
use App\Http\Middleware\EnsureSellerApproved;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsSeller;
use App\Http\Middleware\ResolveSellerActor;
use App\Http\Middleware\SellerAbility;
use App\Http\Middleware\SellerApiToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Csp\AddCspHeaders;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Written by the browser so a refresh can render the right theme straight away.
        $middleware->encryptCookies(except: ['pm-theme']);

        $middleware->alias([
            'seller' => EnsureUserIsSeller::class,
            'seller.approved' => EnsureSellerApproved::class,
            'seller.actor' => ResolveSellerActor::class,
            'seller.can' => SellerAbility::class,
            'admin' => EnsureUserIsAdmin::class,
            'seller.api' => SellerApiToken::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'webhooks/stripe',
            'webhooks/easypost',
            // RFC 8058 one-click unsubscribe: mail clients POST here without a session; the URL is signed.
            'm/unsubscribe/*',
        ]);

        $middleware->web(append: [EnforcePlatformAccess::class]);

        // Content-Security-Policy on every web response. Off unless CSP_ENABLED=true;
        // then report-only until CSP_ENFORCE=true (see config/csp.php).
        if (env('CSP_ENABLED', false)) {
            $middleware->web(append: [AddCspHeaders::class]);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
