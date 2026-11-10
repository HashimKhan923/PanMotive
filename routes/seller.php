<?php

use App\Http\Controllers\Seller\AnalyticsController;
use App\Http\Controllers\Seller\DashboardController;
use App\Http\Controllers\Seller\FinanceController;
use App\Http\Controllers\Seller\FulfilmentController;
use App\Http\Controllers\Seller\InsightsController;
use App\Http\Controllers\Seller\MarketingAnalyticsController;
use App\Http\Controllers\Seller\NotificationController;
use App\Http\Controllers\Seller\OrderController;
use App\Http\Controllers\Seller\PayoutController;
use App\Http\Controllers\Seller\ReturnController;
use App\Http\Controllers\Seller\ReviewController;
use App\Http\Controllers\Seller\ScorecardController;
use App\Http\Controllers\Seller\StripeOnboardingController;
use App\Http\Controllers\Seller\TaxController;
use App\Http\Controllers\Seller\TeamController;
use App\Http\Controllers\Seller\SocialConnectController;
use App\Livewire\Seller\AdManager;
use App\Livewire\Seller\Affiliates;
use App\Livewire\Seller\AccountHealth;
use App\Livewire\Seller\ApiAccess;
use App\Livewire\Seller\Automations;
use App\Livewire\Seller\Bundles;
use App\Livewire\Seller\StoreBuilder;
use App\Livewire\Seller\BulkUpload;
use App\Livewire\Seller\Campaigns;
use App\Livewire\Seller\Inbox;
use App\Livewire\Seller\Inventory;
use App\Livewire\Seller\Marketing;
use App\Livewire\Seller\Offers;
use App\Livewire\Seller\OnboardingWizard;
use App\Livewire\Seller\ProductForm;
use App\Livewire\Seller\ProductList;
use App\Livewire\Seller\Promotions;
use App\Livewire\Seller\ShippingPolicies;
use App\Livewire\Seller\SocialStudio;
use App\Livewire\Seller\StoreSettings;
use App\Livewire\Seller\Team;
use App\Livewire\Seller\Verification;
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

    // Team invitation accept — the invitee is not a seller yet, so this sits outside the seller group.
    Route::get('/team/accept/{token}', [TeamController::class, 'accept'])->name('seller.team.accept');
    Route::get('/onboarding/stripe/return', [StripeOnboardingController::class, 'return'])->name('seller.onboarding.stripe.return');
    Route::get('/onboarding/stripe/refresh', [StripeOnboardingController::class, 'refresh'])->name('seller.onboarding.stripe.refresh');

    Route::middleware(['seller', 'seller.approved', 'seller.actor'])->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('seller.dashboard');

        // Products
        Route::get('/products', ProductList::class)->middleware('seller.can:products.view,products.manage')->name('seller.products.index');
        Route::get('/products/create', ProductForm::class)->middleware('seller.can:products.manage')->name('seller.products.create');
        Route::get('/products/bulk-upload', BulkUpload::class)->middleware('seller.can:products.manage')->name('seller.products.bulk');
        Route::get('/products/bulk-update', \App\Livewire\Seller\BulkUpdate::class)->middleware('seller.can:products.manage')->name('seller.products.bulk-update');
        Route::get('/products/{product}/edit', ProductForm::class)->middleware('seller.can:products.manage')->name('seller.products.edit');

        // Orders
        Route::get('/orders', [OrderController::class, 'index'])->middleware('seller.can:orders.view,orders.manage')->name('seller.orders.index');
        Route::get('/orders/export', [OrderController::class, 'export'])->middleware('seller.can:orders.view,orders.manage')->name('seller.orders.export');
        Route::post('/orders/bulk-accept', [OrderController::class, 'bulkAccept'])->middleware('seller.can:orders.manage')->name('seller.orders.bulk-accept');
        Route::get('/orders/invoice/{order}', [OrderController::class, 'invoice'])->middleware('seller.can:orders.view,orders.manage,fulfilment')->name('seller.orders.invoice');
        Route::get('/orders/{orderItem}', [OrderController::class, 'show'])->middleware('seller.can:orders.view,orders.manage,returns.manage,fulfilment')->name('seller.orders.show');
        Route::post('/orders/{orderItem}/accept', [OrderController::class, 'accept'])->middleware('seller.can:orders.manage')->name('seller.orders.accept');
        Route::post('/orders/{orderItem}/cancel', [OrderController::class, 'cancel'])->middleware('seller.can:orders.manage')->name('seller.orders.cancel');
        Route::post('/orders/{orderItem}/ship', [OrderController::class, 'ship'])->middleware('seller.can:orders.manage')->name('seller.orders.ship');
        Route::post('/orders/{orderItem}/note', [OrderController::class, 'note'])->middleware('seller.can:orders.manage')->name('seller.orders.note');
        Route::post('/orders/{orderItem}/instant-payout', [OrderController::class, 'instantPayout'])->middleware('seller.can:orders.manage')->name('seller.orders.instant-payout');

        // Shipping policies
        Route::get('/shipping', ShippingPolicies::class)->middleware('seller.can:products.manage')->name('seller.shipping.index');

        // Inventory — locations, reorder points, movement log
        Route::get('/inventory', Inventory::class)->middleware('seller.can:inventory.manage')->name('seller.inventory.index');

        // Fulfilment tools
        Route::get('/fulfilment/pick-list', [FulfilmentController::class, 'pickList'])->middleware('seller.can:fulfilment')->name('seller.fulfilment.pick-list');
        Route::get('/fulfilment/{order}/packing-slip', [FulfilmentController::class, 'packingSlip'])->middleware('seller.can:fulfilment')->name('seller.fulfilment.packing-slip');
        Route::get('/fulfilment/{order}/label', [FulfilmentController::class, 'labelForm'])->middleware('seller.can:fulfilment')->name('seller.fulfilment.label');
        Route::post('/fulfilment/{order}/label', [FulfilmentController::class, 'buyLabel'])->middleware('seller.can:fulfilment')->name('seller.fulfilment.buy-label');

        // Returns / RMA queue
        Route::get('/returns', [ReturnController::class, 'index'])->middleware('seller.can:returns.manage,orders.view')->name('seller.returns.index');
        Route::post('/returns/{returnRequest}', [ReturnController::class, 'update'])->middleware('seller.can:returns.manage')->name('seller.returns.update');

        // Customers (CRM)
        Route::get('/customers', \App\Livewire\Seller\Customers::class)->middleware('seller.can:orders.view')->name('seller.customers.index');
        Route::get('/customers/{buyer}', \App\Livewire\Seller\CustomerProfile::class)->middleware('seller.can:orders.view')->name('seller.customers.show');

        // Subscribe & Save subscribers
        Route::get('/subscriptions', \App\Livewire\Seller\Subscriptions::class)->middleware('seller.can:orders.view')->name('seller.subscriptions.index');

        // Product Q&A
        Route::get('/questions', \App\Livewire\Seller\Questions::class)->middleware('seller.can:messages')->name('seller.questions.index');

        // Help & support
        Route::get('/support', \App\Livewire\Seller\Support::class)->name('seller.support.index');

        // Messages
        Route::get('/messages', Inbox::class)->middleware('seller.can:messages')->name('seller.messages.index');

        // Offers
        Route::get('/offers', Offers::class)->middleware('seller.can:orders.view,orders.manage')->name('seller.offers.index');

        // Promotions
        Route::get('/promotions', Promotions::class)->middleware('seller.can:marketing')->name('seller.promotions.index');

        // Marketing (boosts, announcements, tracked links)
        Route::get('/marketing', Marketing::class)->middleware('seller.can:marketing')->name('seller.marketing.index');

        // Campaigns (email / SMS)
        Route::get('/campaigns', Campaigns::class)->middleware('seller.can:marketing')->name('seller.campaigns.index');

        // Marketing automations + insights
        Route::get('/automations', Automations::class)->middleware('seller.can:marketing')->name('seller.automations.index');
        Route::get('/marketing/analytics', [MarketingAnalyticsController::class, 'index'])->middleware('seller.can:marketing')->name('seller.marketing.analytics');

        // Ad manager
        Route::get('/ads', AdManager::class)->middleware('seller.can:marketing')->name('seller.ads.index');

        // Offsite Ads (we advertise listings off-platform; fee only on attributed sales)
        Route::get('/offsite-ads', \App\Livewire\Seller\OffsiteAds::class)->middleware('seller.can:marketing')->name('seller.offsite-ads.index');

        // Ad credit wallet (card top-ups via Stripe Checkout)
        Route::get('/ad-credit', \App\Livewire\Seller\AdCredit::class)->middleware('seller.can:marketing')->name('seller.ad-credit.index');
        Route::get('/ad-credit/return', [\App\Http\Controllers\Seller\AdCreditController::class, 'return'])->middleware('seller.can:marketing')->name('seller.ad-credit.return');

        // Affiliate program
        Route::get('/affiliates', Affiliates::class)->middleware('seller.can:marketing')->name('seller.affiliates.index');

        // Storefront builder
        Route::get('/storefront', StoreBuilder::class)->middleware('seller.can:settings.manage')->name('seller.storefront.edit');

        // Social studio
        Route::get('/social', SocialStudio::class)->middleware('seller.can:marketing')->name('seller.social.index');
        Route::get('/social/connect/{platform}', [SocialConnectController::class, 'connect'])->middleware('seller.can:marketing')->name('seller.social.connect');
        Route::get('/social/callback/{platform}', [SocialConnectController::class, 'callback'])->middleware('seller.can:marketing')->name('seller.social.callback');
        Route::delete('/social/accounts/{socialAccount}', [SocialConnectController::class, 'disconnect'])->middleware('seller.can:marketing')->name('seller.social.disconnect');

        // Performance & tier
        Route::get('/performance', [ScorecardController::class, 'index'])->name('seller.scorecard.index');

        // Account health — violations, appeals, buyer claims
        Route::get('/account-health', AccountHealth::class)->name('seller.account-health.index');

        // Bundles
        Route::get('/bundles', Bundles::class)->middleware('seller.can:products.manage')->name('seller.bundles.index');

        // Insights
        Route::get('/insights', [InsightsController::class, 'index'])->middleware('seller.can:products.view')->name('seller.insights.index');
        Route::post('/insights/{product}/run-sale', [InsightsController::class, 'runSale'])->middleware('seller.can:products.manage')->name('seller.insights.run-sale');

        // Analytics
        Route::get('/analytics', [AnalyticsController::class, 'index'])->middleware('seller.can:finances.view')->name('seller.analytics.index');
        Route::get('/analytics/export', [AnalyticsController::class, 'export'])->middleware('seller.can:finances.view')->name('seller.analytics.export');

        // Reviews
        Route::get('/reviews', \App\Livewire\Seller\Reviews::class)->name('seller.reviews.index');
        Route::post('/reviews/{review}/respond', [ReviewController::class, 'respond'])->middleware('seller.can:messages')->name('seller.reviews.respond');

        // Notifications
        Route::get('/notifications', [NotificationController::class, 'index'])->name('seller.notifications.index');
        Route::post('/notifications/read', [NotificationController::class, 'markAllRead'])->name('seller.notifications.read');

        // Payouts
        Route::get('/payouts', [PayoutController::class, 'index'])->middleware('seller.can:payouts.view')->name('seller.payouts.index');

        // Ledger, P&L and payout reconciliation
        Route::get('/finance/ledger', [FinanceController::class, 'ledger'])->middleware('seller.can:finances.view')->name('seller.finance.ledger');
        Route::get('/finance/export', [FinanceController::class, 'export'])->middleware('seller.can:finances.view')->name('seller.finance.export');

        // Tax & statements
        Route::get('/tax', [TaxController::class, 'index'])->middleware('seller.can:finances.view')->name('seller.tax.index');
        Route::get('/tax/statement', [TaxController::class, 'statement'])->middleware('seller.can:finances.view')->name('seller.tax.statement');

        // API & webhooks
        Route::get('/api', ApiAccess::class)->middleware('seller.can:settings.manage')->name('seller.api.index');

        // Store settings
        Route::get('/settings', StoreSettings::class)->middleware('seller.can:settings.manage')->name('seller.settings.edit');

        // Team & permissions
        Route::get('/team', Team::class)->middleware('seller.can:team.manage')->name('seller.team.index');

        // Identity verification (KYC)
        Route::get('/verification', Verification::class)->middleware('seller.can:settings.manage')->name('seller.verification.index');
    });
});
