<?php

use App\Http\Controllers\Storefront\CheckoutController;
use App\Http\Controllers\Storefront\ProductController;
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

// Catalog, cart, checkout
Route::get('/', fn () => view('storefront.home'))->name('home');
Route::get('/shop', ProductBrowse::class)->name('products.index');
Route::get('/products/{slug}', [ProductController::class, 'show'])->name('products.show');

Route::get('/cart', CartPage::class)->name('cart.index');

// NOTE: no auth middleware yet — add ['auth'] before launch.
Route::get('/checkout', [CheckoutController::class, 'index'])->name('checkout.index');
Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store');
Route::get('/checkout/confirmation/{orderNumber}', [CheckoutController::class, 'confirmation'])
    ->name('checkout.confirmation');

// Authenticated buyer area
Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

// Named logout route (the Breeze/Volt stack logs out via a Livewire action;
// this exposes it as a route so any layout can POST to it).
Route::post('logout', function (Logout $logout) {
    $logout();

    return redirect('/');
})->middleware('auth')->name('logout');

require __DIR__.'/auth.php';
