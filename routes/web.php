<?php

use App\Http\Controllers\Webhooks\EasyPostWebhookController;
use App\Http\Controllers\Webhooks\StripeWebhookController;
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
| routes/seller.php and routes/admin.php each name their own routes in full
| (seller.* / admin.*), so these groups add no ->name() prefix.
*/

Route::post('/impersonate/stop', [\App\Http\Controllers\Admin\ImpersonationController::class, 'stop'])
    ->middleware('auth')->name('impersonate.stop');

// Local dev can't resolve subdomains under `php artisan serve`, so the portals
// are mounted under path prefixes there. Every other environment (incl. testing)
// keeps the production subdomain layout. Route names are identical either way.
if (app()->environment('local')) {
    Route::prefix('seller')->group(function () {
        require __DIR__.'/seller.php';
    });

    Route::prefix('admin')->group(function () {
        require __DIR__.'/admin.php';
    });
} else {
    Route::domain('sell.'.config('app.domain'))
        ->group(function () {
            require __DIR__.'/seller.php';
        });

    Route::domain('admin.'.config('app.domain'))
        ->group(function () {
            require __DIR__.'/admin.php';
        });
}

// Payment / carrier webhooks — apex domain, CSRF-exempt (see bootstrap/app.php).
Route::post('webhooks/stripe', [StripeWebhookController::class, 'handle'])->name('webhooks.stripe');
Route::post('webhooks/easypost', [EasyPostWebhookController::class, 'handle'])->name('webhooks.easypost');

// Storefront (default domain)
require __DIR__.'/storefront.php';
