<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin routes
|--------------------------------------------------------------------------
| Registered in bootstrap/app.php: "web" middleware, "/admin" path prefix,
| "admin." route-name prefix. Target: admin.partsmarket.com (subdomain
| swap-in later). Controllers: App\Http\Controllers\Admin\*
| Livewire: App\Livewire\Admin\*
|
| Add a role/permission gate (spatie/laravel-permission) here once roles
| are seeded, e.g. ->middleware(['auth', 'role:admin']).
*/

Route::middleware(['auth'])->group(function (): void {
    Route::view('/', 'admin.dashboard')->name('dashboard');
});
