<?php

use App\Http\Controllers\Storefront\CartController;
use App\Http\Controllers\Storefront\CheckoutController;
use App\Http\Controllers\Storefront\OrderController;
use App\Http\Controllers\Storefront\ProductController;
use App\Http\Controllers\Storefront\ReturnController;
use App\Http\Controllers\SitemapController;
use App\Livewire\Actions\Logout;
use App\Livewire\Storefront\CartPage;
use App\Livewire\Storefront\ProductBrowse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Storefront routes (default domain)
|--------------------------------------------------------------------------
| Public marketplace + authenticated buyer area + auth screens.
| Controllers: App\Http\Controllers\Storefront\*
| Livewire:    App\Livewire\Storefront\*
*/

// Catalog
Route::get('/', fn () => view('storefront.home'))->name('home');
Route::get('/shop', ProductBrowse::class)->name('products.index');
Route::get('/products/{slug}', [ProductController::class, 'show'])->name('products.show');
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');

// Cart
Route::get('/cart', CartPage::class)->name('cart.index');
Route::post('/cart/add', [CartController::class, 'add'])->name('cart.add');
Route::post('/cart/remove', [CartController::class, 'remove'])->name('cart.remove');

// Checkout
Route::get('/checkout', [CheckoutController::class, 'index'])->name('checkout.index');
Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('auth')->name('checkout.store');
Route::get('/checkout/confirmation/{orderNumber}', [CheckoutController::class, 'confirmation'])
    ->name('checkout.confirmation');

// Authenticated buyer area
Route::middleware('auth')->group(function () {
    Route::view('dashboard', 'dashboard')->middleware('verified')->name('dashboard');
    Route::view('profile', 'profile')->name('profile');

    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/{orderItem}/confirm-delivery', [OrderController::class, 'confirmDelivery'])->name('orders.confirm-delivery');
    Route::post('/orders/{orderItem}/return', [ReturnController::class, 'request'])->name('orders.return');

    // Named logout route (the Breeze/Volt stack logs out via a Livewire action;
    // this exposes it as a route so any layout can POST to it).
    Route::post('logout', function (Logout $logout) {
        $logout();

        return redirect('/');
    })->name('logout');
});

require __DIR__.'/auth.php';
