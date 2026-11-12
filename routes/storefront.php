<?php

use App\Http\Controllers\SitemapController;
use App\Http\Controllers\Storefront\CartController;
use App\Http\Controllers\Storefront\CheckoutController;
use App\Http\Controllers\Storefront\CompareController;
use App\Http\Controllers\Storefront\DealsController;
use App\Http\Controllers\Storefront\OrderController;
use App\Http\Controllers\Storefront\ProductController;
use App\Http\Controllers\Storefront\ReturnController;
use App\Http\Controllers\Storefront\TrackController;
use App\Http\Controllers\Storefront\WalletController;
use App\Http\Controllers\Storefront\WishlistController;
use App\Livewire\Actions\Logout;
use App\Livewire\Storefront\CartPage;
use App\Livewire\Storefront\ComparePage;
use App\Livewire\Storefront\ProductBrowse;
use App\Livewire\Storefront\WishlistPage;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Storefront routes (default domain)
|--------------------------------------------------------------------------
| Public marketplace + authenticated buyer area + auth screens.
*/


// Catalog
Route::get('/', fn () => view('storefront.home'))->name('home');
Route::get('/shop', ProductBrowse::class)->name('products.index');
Route::get('/deals', [DealsController::class, 'index'])->name('deals.index');
Route::get('/products/{slug}', [ProductController::class, 'show'])->name('products.show');

// Offsite Ads catalog feed (Google Merchant RSS — also read by Meta, Pinterest, Microsoft).
Route::get('/feeds/offsite/{channel}.xml', [\App\Http\Controllers\Storefront\OffsiteFeedController::class, 'show'])
    ->where('channel', '[a-z]+')->name('feeds.offsite');
Route::get('/sellers/{sellerProfile}', [\App\Http\Controllers\Storefront\SellerStoreController::class, 'show'])->name('sellers.show');
Route::get('/go/{promoLink}', [\App\Http\Controllers\Storefront\PromoLinkController::class, 'go'])->name('promo.go');
Route::get('/m/unsubscribe/{user}/{seller?}', [\App\Http\Controllers\Marketing\UnsubscribeController::class, 'show'])->middleware('signed')->name('marketing.unsubscribe');
Route::post('/m/unsubscribe/{user}/{seller?}', [\App\Http\Controllers\Marketing\UnsubscribeController::class, 'store'])->middleware(['signed', 'throttle:20,1']);
// "Stop these emails" links in optional emails (price drops, Q&A answers…). Signed, no login needed.
Route::get('/email/stop/{user}/{category}', [\App\Http\Controllers\EmailStopController::class, 'show'])->middleware('signed')->name('email.stop');
Route::post('/email/stop/{user}/{category}', [\App\Http\Controllers\EmailStopController::class, 'store'])->middleware(['signed', 'throttle:20,1']);
Route::get('/e/o/{token}', [\App\Http\Controllers\Marketing\TrackingController::class, 'open'])->name('m.open');
Route::get('/e/c/{token}', [\App\Http\Controllers\Marketing\TrackingController::class, 'click'])->name('m.click');
Route::get('/e/ao/{token}', [\App\Http\Controllers\Marketing\TrackingController::class, 'automationOpen'])->name('am.open');
Route::get('/e/ac/{token}', [\App\Http\Controllers\Marketing\TrackingController::class, 'automationClick'])->name('am.click');
Route::get('/sp/{source}/{ref}', [\App\Http\Controllers\Storefront\AdClickController::class, 'go'])->name('ad.click');
Route::get('/a/{affiliate}', [\App\Http\Controllers\Storefront\AffiliateLinkController::class, 'go'])->name('affiliate.go');
Route::get('/affiliates/{sellerProfile}/join', [\App\Http\Controllers\Storefront\AffiliateLinkController::class, 'joinForm'])->name('affiliate.join.form');
Route::post('/affiliates/{sellerProfile}/join', [\App\Http\Controllers\Storefront\AffiliateLinkController::class, 'join'])->middleware('throttle:6,1')->name('affiliate.join');
Route::get('/p/{page}', function (\App\Models\Page $page) {
    abort_unless($page->is_published, 404);

    return view('storefront.page', compact('page'));
})->name('pages.show');
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');

Route::get('/plus', [\App\Http\Controllers\Storefront\MembershipController::class, 'show'])->name('plus.show');
Route::get('/find-parts', [\App\Http\Controllers\Storefront\PartFinderController::class, 'index'])->name('find-parts');

// Wishlist + compare (guest-friendly)
Route::get('/wishlist', WishlistPage::class)->name('wishlist.index');
Route::get('/wishlist/ids', [WishlistController::class, 'ids'])->name('wishlist.ids');
Route::post('/wishlist/toggle', [WishlistController::class, 'toggle'])->middleware('throttle:60,1')->name('wishlist.toggle');
Route::get('/compare', ComparePage::class)->name('compare.index');
Route::post('/compare/toggle', [CompareController::class, 'toggle'])->middleware('throttle:60,1')->name('compare.toggle');

// Cart
Route::get('/cart', CartPage::class)->name('cart.index');
Route::get('/cart/summary', [CartController::class, 'summary'])->name('cart.summary');
Route::post('/cart/add', [CartController::class, 'add'])->name('cart.add');
Route::post('/cart/bundle/{bundle}', [CartController::class, 'addBundle'])->name('cart.add-bundle');
Route::post('/cart/remove', [CartController::class, 'remove'])->name('cart.remove');

// Checkout — guest checkout supported
Route::get('/checkout', [CheckoutController::class, 'index'])->name('checkout.index');
Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('throttle:checkout')->name('checkout.store');
Route::post('/checkout/apply-coupon', [CheckoutController::class, 'applyCoupon'])->middleware('throttle:checkout')->name('checkout.apply-coupon');
Route::get('/checkout/confirmation/{orderNumber}', [CheckoutController::class, 'confirmation'])->name('checkout.confirmation');

// Guest order tracking (order number + email)
Route::get('/track', [TrackController::class, 'form'])->name('track.form');
Route::view('/fit-guarantee', 'storefront.fit-guarantee')->name('fit-guarantee');
Route::post('/track', [TrackController::class, 'lookup'])->middleware('throttle:10,1')->name('track.lookup');

// Authenticated buyer area
Route::middleware('auth')->group(function () {
    Route::view('dashboard', 'dashboard')->middleware('verified')->name('dashboard');
    Route::view('profile', 'profile')->name('profile');


    Route::get('/wallet', [WalletController::class, 'index'])->name('wallet.index');

    Route::get('/messages', \App\Livewire\Storefront\BuyerMessages::class)->name('messages.index');
    Route::get('/account/marketing', \App\Livewire\Storefront\MarketingPreferences::class)->name('marketing.preferences');
    Route::get('/affiliate', [\App\Http\Controllers\Storefront\AffiliateLinkController::class, 'dashboard'])->name('affiliate.dashboard');
    Route::post('/loyalty/{sellerProfile}/redeem', [\App\Http\Controllers\Storefront\LoyaltyController::class, 'redeem'])->name('loyalty.redeem');

    Route::post('/plus/join', [\App\Http\Controllers\Storefront\MembershipController::class, 'join'])->name('plus.join');
    Route::post('/plus/cancel', [\App\Http\Controllers\Storefront\MembershipController::class, 'cancel'])->name('plus.cancel');

    Route::get('/subscriptions', [\App\Http\Controllers\Storefront\SubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::post('/subscriptions', [\App\Http\Controllers\Storefront\SubscriptionController::class, 'store'])->name('subscriptions.store');
    Route::patch('/subscriptions/{subscription}', [\App\Http\Controllers\Storefront\SubscriptionController::class, 'update'])->name('subscriptions.update');

    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::get('/orders/{order}/pay', [OrderController::class, 'pay'])->name('orders.pay');
    Route::post('/orders/{orderItem}/contact-seller', [OrderController::class, 'contactSeller'])->middleware('throttle:20,1')->name('orders.contact-seller');
    Route::post('/orders/{orderItem}/confirm-delivery', [OrderController::class, 'confirmDelivery'])->name('orders.confirm-delivery');
    Route::post('/orders/{orderItem}/return', [ReturnController::class, 'request'])->name('orders.return');
    Route::post('/returns/{returnRequest}/escalate', [ReturnController::class, 'escalate'])->name('orders.return.escalate');
    Route::post('/orders/{orderItem}/claim', [\App\Http\Controllers\Storefront\ClaimController::class, 'store'])->middleware('throttle:6,1')->name('orders.claim');

    Route::post('logout', function (Logout $logout) {
        $logout();

        return redirect('/');
    })->name('logout');
});

require __DIR__.'/auth.php';
