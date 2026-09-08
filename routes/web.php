<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Domain router (entry point)
|--------------------------------------------------------------------------
| Splits traffic by host. Seller and admin live on subdomains; everything
| else is the storefront on the default domain. APP_DOMAIN drives the host
| match (config('app.domain')).
|
| Local dev: subdomain groups only match when the request actually arrives
| on sell.<domain> / admin.<domain>. Herd/Valet resolve *.test automatically;
| with `php artisan serve` add the hosts entries (or just use the storefront,
| which always matches).
|
| The ->name() prefixes keep route names unique across the three areas
| (seller.dashboard, admin.dashboard, dashboard).
*/

Route::domain('sell.'.config('app.domain'))
    ->name('seller.')
    ->group(function () {
        require __DIR__.'/seller.php';
    });

Route::domain('admin.'.config('app.domain'))
    ->name('admin.')
    ->group(function () {
        require __DIR__.'/admin.php';
    });

// Storefront (default domain)
require __DIR__.'/storefront.php';
