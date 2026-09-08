<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Seller portal — path prefix for now; swap to Route::domain('sell.'.config('app.domain'))
            // once the subdomain is provisioned.
            Route::middleware('web')
                ->prefix('seller')
                ->as('seller.')
                ->group(base_path('routes/seller.php'));

            // Admin panel — path prefix for now; swap to Route::domain('admin.'.config('app.domain')) later.
            Route::middleware('web')
                ->prefix('admin')
                ->as('admin.')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
