<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Storefront routes
|--------------------------------------------------------------------------
| Public marketplace + authenticated buyer area. Seller and admin routes
| live in routes/seller.php and routes/admin.php (registered in
| bootstrap/app.php with their own prefixes).
*/

Route::view('/', 'welcome')->name('home');

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
