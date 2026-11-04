<?php

use App\Http\Controllers\Admin\AdsController;
use App\Http\Controllers\Admin\AlertController;
use App\Http\Controllers\Admin\AppealController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\BroadcastController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ContentController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DisputeController;
use App\Http\Controllers\Admin\FinanceController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\InsightsController;
use App\Http\Controllers\Admin\OffsiteAdsController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\OversightController;
use App\Http\Controllers\Admin\PageController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ReturnController;
use App\Http\Controllers\Admin\ReviewController;
use App\Http\Controllers\Admin\SearchController;
use App\Http\Controllers\Admin\SellerApprovalController;
use App\Http\Controllers\Admin\SellerController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\SupportController;
use App\Http\Controllers\Admin\SystemController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VehicleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin console routes
|--------------------------------------------------------------------------
| Loaded from routes/web.php inside Route::domain('admin.'.config('app.domain')).
| "web" middleware is applied by the framework. Route names are declared in
| full here (admin.*) — the domain group adds no prefix. The 'admin' alias
| (EnsureUserIsAdmin) gates on role; each section below adds its permission.
*/

Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('admin.dashboard');
    Route::get('/insights', [InsightsController::class, 'index'])->middleware('can:finance.view')->name('admin.insights.index');
    Route::get('/search', SearchController::class)->middleware('throttle:60,1')->name('admin.search');

    // ---------- People ----------
    Route::middleware('can:users.view')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('admin.users.index');
        Route::get('/users/{user}', [UserController::class, 'show'])->name('admin.users.show');
    });
    Route::middleware('can:users.manage')->group(function () {
        Route::patch('/users/{user}', [UserController::class, 'update'])->name('admin.users.update');
        Route::post('/users/{user}/ban', [UserController::class, 'ban'])->name('admin.users.ban');
        Route::post('/users/{user}/unban', [UserController::class, 'unban'])->name('admin.users.unban');
        Route::post('/users/{user}/password-reset', [UserController::class, 'sendPasswordReset'])->name('admin.users.password-reset');
        Route::post('/users/{user}/plus', [UserController::class, 'setPlus'])->name('admin.users.plus');
    });
    Route::post('/users/{user}/wallet', [UserController::class, 'adjustWallet'])->middleware('can:wallet.adjust')->name('admin.users.wallet');
    Route::post('/users/{user}/impersonate', [ImpersonationController::class, 'start'])->middleware('can:users.impersonate')->name('admin.users.impersonate');

    Route::middleware('can:sellers.approve')->group(function () {
        Route::get('/sellers', [SellerController::class, 'index'])->name('admin.sellers.index');
        Route::get('/sellers/pending', fn () => redirect()->route('admin.sellers.index', ['status' => 'pending']))->name('admin.sellers.pending');
        Route::get('/sellers/verifications', [SellerApprovalController::class, 'verifications'])->name('admin.verifications.index');
        Route::post('/verifications/{sellerVerification}/review', [SellerApprovalController::class, 'reviewVerification'])->name('admin.verifications.review');
        Route::get('/verifications/{sellerVerification}/document/{type}', [SellerApprovalController::class, 'document'])->name('admin.verifications.document');
        Route::get('/sellers/{sellerProfile}', [SellerController::class, 'show'])->name('admin.sellers.show');
        Route::post('/sellers/{sellerProfile}/approve', [SellerApprovalController::class, 'approve'])->name('admin.sellers.approve');
        Route::post('/sellers/{sellerProfile}/reject', [SellerApprovalController::class, 'reject'])->name('admin.sellers.reject');
        Route::get('/appeals', [AppealController::class, 'index'])->name('admin.appeals.index');
        Route::post('/appeals/{policyViolation}/decide', [AppealController::class, 'decide'])->name('admin.appeals.decide');
    });
    Route::post('/sellers/{sellerProfile}/suspend', [SellerApprovalController::class, 'suspend'])->name('admin.sellers.suspend');
    Route::post('/sellers/{sellerProfile}/reinstate', [SellerApprovalController::class, 'reinstate'])->middleware('can:sellers.suspend')->name('admin.sellers.reinstate');
    Route::middleware('can:sellers.manage')->group(function () {
        Route::post('/sellers/{sellerProfile}/commission', [SellerController::class, 'commission'])->name('admin.sellers.commission');
        Route::post('/sellers/{sellerProfile}/tier', [SellerController::class, 'tier'])->name('admin.sellers.tier');
        Route::post('/sellers/{sellerProfile}/payout-hold', [SellerController::class, 'payoutHold'])->name('admin.sellers.payout-hold');
        Route::post('/sellers/{sellerProfile}/notes', [SellerController::class, 'notes'])->name('admin.sellers.notes');
        Route::post('/sellers/{sellerProfile}/violation', [SellerController::class, 'violation'])->name('admin.sellers.violation');
    });

    Route::middleware('can:staff.manage')->group(function () {
        Route::get('/staff', [StaffController::class, 'index'])->name('admin.staff.index');
        Route::post('/staff', [StaffController::class, 'store'])->name('admin.staff.store');
        Route::post('/staff/matrix', [StaffController::class, 'matrix'])->name('admin.staff.matrix');
        Route::post('/staff/{user}/role', [StaffController::class, 'role'])->name('admin.staff.role');
        Route::delete('/staff/{user}', [StaffController::class, 'remove'])->name('admin.staff.remove');
    });

    // ---------- Catalog ----------
    Route::middleware('can:catalog.manage')->group(function () {
        Route::get('/products', [ProductController::class, 'index'])->name('admin.products.index');
        Route::post('/products/bulk', [ProductController::class, 'bulk'])->name('admin.products.bulk');
        Route::get('/products/{product}', [ProductController::class, 'show'])->name('admin.products.show');
        Route::patch('/products/{product}', [ProductController::class, 'update'])->name('admin.products.update');
        Route::post('/products/{product}/moderate', [ProductController::class, 'moderate'])->name('admin.products.moderate');

        Route::get('/categories', [CategoryController::class, 'index'])->name('admin.categories.index');
        Route::post('/categories', [CategoryController::class, 'store'])->name('admin.categories.store');
        Route::patch('/categories/{category}', [CategoryController::class, 'update'])->name('admin.categories.update');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('admin.categories.destroy');

        Route::get('/brands', [BrandController::class, 'index'])->name('admin.brands.index');
        Route::post('/brands', [BrandController::class, 'store'])->name('admin.brands.store');
        Route::patch('/brands/{brand}', [BrandController::class, 'update'])->name('admin.brands.update');
        Route::post('/brands/{brand}/merge', [BrandController::class, 'merge'])->name('admin.brands.merge');
        Route::delete('/brands/{brand}', [BrandController::class, 'destroy'])->name('admin.brands.destroy');

        Route::get('/vehicles', [VehicleController::class, 'index'])->name('admin.vehicles.index');
        Route::post('/vehicles/makes', [VehicleController::class, 'storeMake'])->name('admin.vehicles.makes.store');
        Route::delete('/vehicles/makes/{vehicleMake}', [VehicleController::class, 'destroyMake'])->name('admin.vehicles.makes.destroy');
        Route::post('/vehicles/models', [VehicleController::class, 'storeModel'])->name('admin.vehicles.models.store');
        Route::delete('/vehicles/models/{vehicleModel}', [VehicleController::class, 'destroyModel'])->name('admin.vehicles.models.destroy');
        Route::post('/vehicles/years', [VehicleController::class, 'storeYears'])->name('admin.vehicles.years.store');
        Route::delete('/vehicles/{vehicle}', [VehicleController::class, 'destroyVehicle'])->name('admin.vehicles.destroy');
    });
    Route::middleware('can:reviews.moderate')->group(function () {
        Route::get('/reviews', [ReviewController::class, 'index'])->name('admin.reviews.index');
        Route::post('/reviews/{review}/decide', [ReviewController::class, 'decide'])->name('admin.reviews.decide');
        Route::get('/questions', [ReviewController::class, 'questions'])->name('admin.questions.index');
        Route::post('/questions/{question}/decide', [ReviewController::class, 'decideQuestion'])->name('admin.questions.decide');
    });

    // ---------- Orders ----------
    Route::middleware('can:orders.view')->group(function () {
        Route::get('/orders', [OrderController::class, 'index'])->name('admin.orders.index');
        Route::get('/orders/export', [OrderController::class, 'export'])->middleware('can:finance.export')->name('admin.orders.export');
        Route::get('/orders/{order}', [OrderController::class, 'show'])->name('admin.orders.show');
        Route::post('/orders/{order}/note', [OrderController::class, 'note'])->name('admin.orders.note');
        Route::get('/returns', [ReturnController::class, 'index'])->name('admin.returns.index');
        Route::get('/subscriptions', [OversightController::class, 'subscriptions'])->name('admin.subscriptions.index');
        Route::post('/subscriptions/{subscription}/action', [OversightController::class, 'subscriptionAction'])->name('admin.subscriptions.action');
        Route::get('/disputes', [DisputeController::class, 'index'])->name('admin.disputes.index');
    });
    Route::post('/order-items/{orderItem}/cancel', [OrderController::class, 'cancelLine'])->name('admin.order-items.cancel');
    Route::post('/order-items/{orderItem}/refund', [OrderController::class, 'refundLine'])->name('admin.order-items.refund');
    Route::post('/order-items/{orderItem}/mark-delivered', [OrderController::class, 'markDelivered'])->name('admin.order-items.delivered');
    Route::post('/returns/{returnRequest}/act', [ReturnController::class, 'act'])->name('admin.returns.act');
    Route::post('/disputes/{orderItem}/approve-return', [DisputeController::class, 'approveReturn'])->name('admin.disputes.approve-return');
    Route::post('/disputes/{orderItem}/deny-return', [DisputeController::class, 'denyReturn'])->name('admin.disputes.deny-return');
    Route::post('/disputes/{orderItem}/reverse-payout', [DisputeController::class, 'reverseSellerPayout'])->name('admin.disputes.reverse-payout');
    Route::post('/claims/{buyerClaim}/decide', [DisputeController::class, 'decideClaim'])->name('admin.claims.decide');

    // ---------- Money ----------
    Route::middleware('can:finance.view')->group(function () {
        Route::get('/finance', [FinanceController::class, 'overview'])->name('admin.finance.overview');
        Route::get('/finance/ledger', [FinanceController::class, 'ledger'])->name('admin.finance.ledger');
        Route::get('/finance/payouts', [FinanceController::class, 'payouts'])->name('admin.finance.payouts');
        Route::post('/finance/payouts/{orderItem}/retry', [FinanceController::class, 'retryPayout'])->middleware('can:refunds.issue')->name('admin.finance.payouts.retry');
        Route::get('/finance/wallets', [FinanceController::class, 'wallets'])->name('admin.finance.wallets');
        Route::get('/finance/tax', [FinanceController::class, 'tax'])->name('admin.finance.tax');
    });
    Route::get('/finance/export', [FinanceController::class, 'exportCsv'])->name('admin.finance.export');

    // ---------- Growth ----------
    Route::middleware('can:coupons.manage')->group(function () {
        Route::get('/coupons', [CouponController::class, 'index'])->name('admin.coupons.index');
        Route::post('/coupons', [CouponController::class, 'store'])->name('admin.coupons.store');
        Route::post('/coupons/{coupon}/toggle', [CouponController::class, 'toggle'])->name('admin.coupons.toggle');
    });
    Route::middleware('can:marketing.manage')->group(function () {
        Route::get('/ads', [AdsController::class, 'index'])->name('admin.ads.index');
        Route::post('/ads/campaigns/{adCampaign}/toggle', [AdsController::class, 'toggleCampaign'])->name('admin.ads.campaigns.toggle');
        Route::post('/ads/boosts/{boost}/end', [AdsController::class, 'endBoost'])->name('admin.ads.boosts.end');
        Route::get('/offsite-ads', [OffsiteAdsController::class, 'index'])->name('admin.offsite-ads.index');
        Route::post('/offsite-ads/spend', [OffsiteAdsController::class, 'storeSpend'])->name('admin.offsite-ads.spend');
        Route::get('/broadcasts', [BroadcastController::class, 'index'])->name('admin.broadcasts.index');
        Route::post('/broadcasts', [BroadcastController::class, 'store'])->name('admin.broadcasts.store');
        Route::post('/broadcasts/{broadcast}/test', [BroadcastController::class, 'test'])->middleware('throttle:10,1')->name('admin.broadcasts.test');
        Route::post('/broadcasts/{broadcast}/send', [BroadcastController::class, 'send'])->name('admin.broadcasts.send');
        Route::delete('/broadcasts/{broadcast}', [BroadcastController::class, 'destroy'])->name('admin.broadcasts.destroy');
        Route::get('/affiliates', [OversightController::class, 'affiliates'])->name('admin.affiliates.index');
        Route::post('/affiliates/{affiliate:id}/status', [OversightController::class, 'affiliateStatus'])->name('admin.affiliates.status');
        Route::post('/affiliate-conversions/{conversion}/void', [OversightController::class, 'voidConversion'])->name('admin.affiliates.void');
        Route::get('/campaigns', [OversightController::class, 'campaigns'])->name('admin.campaigns.index');
        Route::post('/campaigns/{campaign}/stop', [OversightController::class, 'stopCampaign'])->name('admin.campaigns.stop');
        Route::post('/automations/{automation}/stop', [OversightController::class, 'stopAutomation'])->name('admin.automations.stop');
    });

    // ---------- Content ----------
    Route::middleware('can:content.manage')->group(function () {
        Route::get('/content', [ContentController::class, 'index'])->name('admin.content.index');
        Route::post('/content', [ContentController::class, 'update'])->name('admin.content.update');
        Route::post('/content/home', [ContentController::class, 'updateHome'])->name('admin.content.home');
        Route::get('/pages', [PageController::class, 'index'])->name('admin.pages.index');
        Route::get('/pages/create', [PageController::class, 'create'])->name('admin.pages.create');
        Route::post('/pages', [PageController::class, 'store'])->name('admin.pages.store');
        Route::get('/pages/{page}/edit', [PageController::class, 'edit'])->name('admin.pages.edit');
        Route::patch('/pages/{page}', [PageController::class, 'update'])->name('admin.pages.update');
        Route::delete('/pages/{page}', [PageController::class, 'destroy'])->name('admin.pages.destroy');
    });

    // ---------- Support ----------
    Route::get('/support', [SupportController::class, 'index'])->name('admin.support.index');
    Route::get('/support/{ticket}', [SupportController::class, 'show'])->name('admin.support.show');
    Route::post('/support/{ticket}/reply', [SupportController::class, 'reply'])->name('admin.support.reply');
    Route::post('/support/{ticket}/resolve', [SupportController::class, 'resolve'])->name('admin.support.resolve');

    Route::get('/alerts', [AlertController::class, 'index'])->name('admin.alerts.index');
    Route::post('/alerts/{alert}/resolve', [AlertController::class, 'resolve'])->name('admin.alerts.resolve');

    // ---------- Platform ----------
    Route::middleware('can:settings.manage')->group(function () {
        Route::get('/settings', [SettingsController::class, 'index'])->name('admin.settings.index');
        Route::post('/settings', [SettingsController::class, 'update'])->name('admin.settings.update');
    });
    Route::middleware('can:audit.view')->group(function () {
        Route::get('/audit', [AuditController::class, 'index'])->name('admin.audit.index');
        Route::get('/audit/export', [AuditController::class, 'export'])->name('admin.audit.export');
    });
    Route::middleware('can:system.manage')->group(function () {
        Route::get('/system', [SystemController::class, 'index'])->name('admin.system.index');
        Route::post('/system/cache', [SystemController::class, 'clearCache'])->name('admin.system.cache');
        Route::post('/system/failed/{uuid}/retry', [SystemController::class, 'retryJob'])->name('admin.system.retry');
        Route::delete('/system/failed/{uuid}', [SystemController::class, 'forgetJob'])->name('admin.system.forget');
    });
});
