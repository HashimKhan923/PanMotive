<?php

use App\Http\Controllers\Seller\DashboardController;
use App\Http\Controllers\Seller\OrderController;
use App\Http\Controllers\Seller\StripeOnboardingController;
use App\Livewire\Seller\BulkUpload;
use App\Livewire\Seller\OnboardingWizard;
use App\Livewire\Seller\ProductForm;
use App\Livewire\Seller\ProductList;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Seller portal routes
|--------------------------------------------------------------------------
| Loaded from routes/web.php inside Route::domain('sell.'.config('app.domain')).
| "web" middleware is applied by the framework. Route names are declared in
| full here (seller.*) — the domain group adds no prefix.
*/

Route::middleware(['auth'])->group(function () {
    Route::get('/onboarding', OnboardingWizard::class)->name('seller.onboarding.start');
    Route::get('/onboarding/pending', fn () => view('seller.onboarding-pending'))->name('seller.onboarding.pending');
    Route::get('/onboarding/stripe/return', [StripeOnboardingController::class, 'return'])->name('seller.onboarding.stripe.return');
    Route::get('/onboarding/stripe/refresh', [StripeOnboardingController::class, 'refresh'])->name('seller.onboarding.stripe.refresh');

    Route::middleware(['seller', 'seller.approved', '2fa'])->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('seller.dashboard');

        Route::get('/products', ProductList::class)->name('seller.products.index');
        Route::get('/products/create', ProductForm::class)->name('seller.products.create');
        Route::get('/products/bulk-upload', BulkUpload::class)->name('seller.products.bulk');
        Route::get('/products/{product}/edit', ProductForm::class)->name('seller.products.edit');

        Route::get('/orders', [OrderController::class, 'index'])->name('seller.orders.index');
        Route::post('/orders/{orderItem}/accept', [OrderController::class, 'accept'])->name('seller.orders.accept');
        Route::post('/orders/{orderItem}/ship', [OrderController::class, 'ship'])->name('seller.orders.ship');
    });
});
