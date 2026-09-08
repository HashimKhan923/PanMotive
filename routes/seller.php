<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Seller portal routes
|--------------------------------------------------------------------------
| Loaded from routes/web.php inside a Route::domain('sell.'.config('app.domain'))
| group with the "seller." route-name prefix. "web" middleware is applied by
| the framework (these are required from the web route file).
| Controllers: App\Http\Controllers\Seller\*
| Livewire:    App\Livewire\Seller\*
*/

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::view('/', 'seller.dashboard')->name('dashboard');
});
