<?php

use App\Http\Controllers\Admin\AlertController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DisputeController;
use App\Http\Controllers\Admin\FinanceController;
use App\Http\Controllers\Admin\SellerApprovalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin console routes
|--------------------------------------------------------------------------
| Loaded from routes/web.php inside Route::domain('admin.'.config('app.domain')).
| "web" middleware is applied by the framework. Route names are declared in
| full here (admin.*) — the domain group adds no prefix. The 'admin' alias
| (EnsureUserIsAdmin) gates on role; controllers add per-permission checks.
*/

Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('admin.dashboard');

    Route::get('/sellers/pending', [SellerApprovalController::class, 'index'])->name('admin.sellers.pending');
    Route::get('/sellers/{sellerProfile}', [SellerApprovalController::class, 'show'])->name('admin.sellers.show');
    Route::post('/sellers/{sellerProfile}/approve', [SellerApprovalController::class, 'approve'])->name('admin.sellers.approve');
    Route::post('/sellers/{sellerProfile}/reject', [SellerApprovalController::class, 'reject'])->name('admin.sellers.reject');
    Route::post('/sellers/{sellerProfile}/suspend', [SellerApprovalController::class, 'suspend'])->name('admin.sellers.suspend');

    Route::get('/disputes', [DisputeController::class, 'index'])->name('admin.disputes.index');
    Route::post('/disputes/{orderItem}/approve-return', [DisputeController::class, 'approveReturn'])->name('admin.disputes.approve-return');
    Route::post('/disputes/{orderItem}/deny-return', [DisputeController::class, 'denyReturn'])->name('admin.disputes.deny-return');
    Route::post('/disputes/{orderItem}/reverse-payout', [DisputeController::class, 'reverseSellerPayout'])->name('admin.disputes.reverse-payout');

    Route::get('/finance/ledger', [FinanceController::class, 'ledger'])->name('admin.finance.ledger');
    Route::get('/finance/export', [FinanceController::class, 'exportCsv'])->name('admin.finance.export');

    Route::get('/alerts', [AlertController::class, 'index'])->name('admin.alerts.index');
    Route::post('/alerts/{alert}/resolve', [AlertController::class, 'resolve'])->name('admin.alerts.resolve');
});
