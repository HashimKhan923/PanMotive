<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin routes
|--------------------------------------------------------------------------
| Loaded from routes/web.php inside a Route::domain('admin.'.config('app.domain'))
| group with the "admin." route-name prefix. "web" middleware is applied by
| the framework (these are required from the web route file).
| Controllers: App\Http\Controllers\Admin\*
| Livewire:    App\Livewire\Admin\*
|
| Lock this down with a role gate once roles are seeded, e.g.
| ->middleware(['auth', 'role:super_admin|support_agent|finance|catalog_manager']).
*/

Route::middleware(['auth'])->group(function (): void {
    Route::view('/', 'admin.dashboard')->name('dashboard');
});
