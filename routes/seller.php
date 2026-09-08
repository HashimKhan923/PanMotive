<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Seller portal routes
|--------------------------------------------------------------------------
| Registered in bootstrap/app.php: "web" middleware, "/seller" path prefix,
| "seller." route-name prefix. Target: sell.partsmarket.com (subdomain
| swap-in later). Controllers: App\Http\Controllers\Seller\*
| Livewire: App\Livewire\Seller\*
*/

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::view('/', 'seller.dashboard')->name('dashboard');
});
