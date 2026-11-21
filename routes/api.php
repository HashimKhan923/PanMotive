<?php

use App\Http\Controllers\Api\Seller\OrderController;
use App\Http\Controllers\Api\Seller\ProductController;
use App\Http\Controllers\Api\Shop;
use App\Http\Middleware\ShopApiContext;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Seller API v1
|--------------------------------------------------------------------------
| Token auth: `Authorization: Bearer pm_live_…`. Scopes are checked per route
| via the middleware parameter (products:read, products:write, orders:read,
| orders:write). Manage tokens at sell.<domain>/api.
*/

// 120 requests a minute per token (or IP when no token) — protects the store and the platform.
Route::prefix('v1')->middleware('throttle:seller-api')->group(function () {
    Route::middleware('seller.api:products:read')->group(function () {
        Route::get('/products', [ProductController::class, 'index']);
        Route::get('/products/{product}', [ProductController::class, 'show']);
    });

    Route::middleware('seller.api:products:write')->group(function () {
        Route::patch('/products/{product}', [ProductController::class, 'update']);
    });

    Route::middleware('seller.api:orders:read')->group(function () {
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{orderItem}', [OrderController::class, 'show']);
    });

    Route::middleware('seller.api:orders:write')->group(function () {
        Route::post('/orders/{orderItem}/accept', [OrderController::class, 'accept']);
        Route::post('/orders/{orderItem}/ship', [OrderController::class, 'ship']);
    });
});

/*
|--------------------------------------------------------------------------
| Shopping app API v1 — /api/shop/v1
|--------------------------------------------------------------------------
| For the Panmotive iPhone / Android app (the shop only — never the seller or admin portals).
| Optional "Authorization: Bearer <token>" from /auth/*, plus X-Device-Id (this install) and
| X-Vehicle-Id (the garage vehicle). See App\Http\Middleware\ShopApiContext.
*/
Route::prefix('shop/v1')->middleware([ShopApiContext::class, 'throttle:shop-api'])
    ->name('api.shop.')->group(function () {

        // Signing in
        Route::middleware('throttle:shop-auth')->group(function () {
            Route::post('/auth/register', [Shop\AuthController::class, 'register'])->name('auth.register');
            Route::post('/auth/login', [Shop\AuthController::class, 'login'])->name('auth.login');
            Route::post('/auth/social/{provider}', [Shop\AuthController::class, 'social'])->name('auth.social');
            Route::post('/auth/forgot-password', [Shop\AuthController::class, 'forgotPassword'])->name('auth.forgot');
        });

        // Browsing (guests welcome)
        Route::get('/home', [Shop\CatalogController::class, 'home'])->name('home');
        Route::get('/categories', [Shop\CatalogController::class, 'categories'])->name('categories');
        Route::get('/products', [Shop\CatalogController::class, 'products'])->name('products');
        Route::get('/products/by-ids', [Shop\CatalogController::class, 'byIds'])->name('products.ids');
        Route::get('/products/{slug}', [Shop\CatalogController::class, 'product'])->name('product');
        Route::get('/products/{id}/related', [Shop\CatalogController::class, 'related'])->whereNumber('id')->name('product.related');
        Route::get('/products/{id}/reviews', [Shop\EngagementController::class, 'reviews'])->whereNumber('id')->name('product.reviews');
        Route::get('/products/{id}/questions', [Shop\EngagementController::class, 'questions'])->whereNumber('id')->name('product.questions');
        Route::post('/reviews/{id}/helpful', [Shop\EngagementController::class, 'helpful'])->whereNumber('id')->name('reviews.helpful');
        Route::get('/deals', [Shop\CatalogController::class, 'deals'])->name('deals');
        Route::get('/search/suggest', [Shop\CatalogController::class, 'suggest'])->name('suggest');
        Route::get('/stores/{sellerProfile}', [Shop\SellerController::class, 'show'])->name('store');
        Route::get('/stores/{sellerProfile}/products', [Shop\SellerController::class, 'products'])->name('store.products');

        // Vehicles + garage
        Route::get('/vehicles/makes', [Shop\VehicleController::class, 'makes'])->name('vehicles.makes');
        Route::get('/vehicles/makes/{make}/models', [Shop\VehicleController::class, 'models'])->name('vehicles.models');
        Route::get('/vehicles/models/{model}/years', [Shop\VehicleController::class, 'years'])->name('vehicles.years');
        Route::get('/vehicles/models/{model}/years/{year}/engines', [Shop\VehicleController::class, 'engines'])->whereNumber('year')->name('vehicles.engines');
        Route::get('/vehicles/resolve', [Shop\VehicleController::class, 'resolve'])->name('vehicles.resolve');
        Route::post('/vehicles/vin', [Shop\VehicleController::class, 'vin'])->middleware('throttle:30,1')->name('vehicles.vin');

        // Wishlist (guests keep theirs on the phone's device id until they sign in)
        Route::get('/wishlist', [Shop\EngagementController::class, 'wishlist'])->name('wishlist');
        Route::get('/wishlist/ids', [Shop\EngagementController::class, 'wishlistIds'])->name('wishlist.ids');
        Route::post('/wishlist/toggle', [Shop\EngagementController::class, 'toggleWishlist'])->name('wishlist.toggle');

        // Cart + checkout (guests welcome — guest checkout like the website)
        Route::get('/cart', [Shop\CartController::class, 'show'])->name('cart');
        Route::post('/cart/items', [Shop\CartController::class, 'add'])->name('cart.add');
        Route::patch('/cart/items', [Shop\CartController::class, 'update'])->name('cart.update');
        Route::delete('/cart/items', [Shop\CartController::class, 'remove'])->name('cart.remove');
        Route::post('/cart/bundles/{bundle}', [Shop\CartController::class, 'addBundle'])->name('cart.bundle');
        Route::post('/cart/coupon', [Shop\CartController::class, 'coupon'])->middleware('throttle:20,1')->name('cart.coupon');
        Route::post('/checkout', [Shop\CartController::class, 'checkout'])->middleware('throttle:checkout')->name('checkout');
        Route::get('/orders/{number}/status', [Shop\OrderController::class, 'status'])->name('orders.status');
        Route::post('/orders/track', [Shop\OrderController::class, 'track'])->middleware('throttle:10,1')->name('orders.track');
        Route::get('/orders/{number}', [Shop\OrderController::class, 'show'])->name('orders.show');
        Route::post('/orders/{number}/pay', [Shop\OrderController::class, 'pay'])->name('orders.pay');
        Route::get('/compare', [Shop\AccountController::class, 'compare'])->name('compare');
        Route::get('/plus', [Shop\AccountController::class, 'plus'])->name('plus');

        // Signed in only
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/orders', [Shop\OrderController::class, 'index'])->name('orders');
            Route::post('/order-items/{orderItem}/confirm-delivery', [Shop\OrderController::class, 'confirmDelivery'])->name('items.delivered');
            Route::post('/order-items/{orderItem}/return', [Shop\OrderController::class, 'requestReturn'])->name('items.return');
            Route::post('/order-items/{orderItem}/claim', [Shop\OrderController::class, 'claim'])->middleware('throttle:6,1')->name('items.claim');
            Route::post('/order-items/{orderItem}/contact-seller', [Shop\OrderController::class, 'contactSeller'])->middleware('throttle:20,1')->name('items.contact');
            Route::post('/returns/{returnRequest}/escalate', [Shop\OrderController::class, 'escalateReturn'])->name('returns.escalate');

            Route::get('/messages', [Shop\AccountController::class, 'conversations'])->name('messages');
            Route::get('/messages/{id}', [Shop\AccountController::class, 'conversation'])->whereNumber('id')->name('messages.show');
            Route::post('/messages/{id}', [Shop\AccountController::class, 'reply'])->whereNumber('id')->middleware('throttle:30,1')->name('messages.reply');
            Route::post('/products/{id}/message-seller', [Shop\AccountController::class, 'messageSeller'])->whereNumber('id')->middleware('throttle:20,1')->name('product.message');

            Route::get('/wallet', [Shop\AccountController::class, 'wallet'])->name('wallet');
            Route::post('/plus/join', [Shop\AccountController::class, 'joinPlus'])->name('plus.join');
            Route::post('/plus/cancel', [Shop\AccountController::class, 'cancelPlus'])->name('plus.cancel');

            Route::get('/subscriptions', [Shop\AccountController::class, 'subscriptions'])->name('subscriptions');
            Route::post('/subscriptions', [Shop\AccountController::class, 'subscribe'])->name('subscriptions.store');
            Route::patch('/subscriptions/{subscription}', [Shop\AccountController::class, 'updateSubscription'])->name('subscriptions.update');

            Route::get('/products/{id}/offer', [Shop\AccountController::class, 'myOffer'])->whereNumber('id')->name('offer');
            Route::post('/products/{id}/offer', [Shop\AccountController::class, 'makeOffer'])->whereNumber('id')->middleware('throttle:10,1')->name('offer.make');
            Route::post('/products/{id}/offer/counter', [Shop\AccountController::class, 'respondToCounter'])->whereNumber('id')->name('offer.counter');
            Route::post('/products/{id}/offer/buy', [Shop\AccountController::class, 'buyOffer'])->whereNumber('id')->name('offer.buy');

            Route::post('/stores/{sellerProfile}/points/redeem', [Shop\AccountController::class, 'redeemPoints'])->name('store.points');
            Route::post('/stores/{sellerProfile}/affiliate', [Shop\AccountController::class, 'joinAffiliate'])->middleware('throttle:6,1')->name('store.affiliate');
            Route::get('/affiliates', [Shop\AccountController::class, 'affiliates'])->name('affiliates');

            Route::get('/preferences', [Shop\AccountController::class, 'preferences'])->name('preferences');
            Route::patch('/preferences', [Shop\AccountController::class, 'updatePreferences'])->name('preferences.update');
            Route::post('/push-tokens', [Shop\AccountController::class, 'registerPush'])->name('push.register');
            Route::delete('/push-tokens', [Shop\AccountController::class, 'unregisterPush'])->name('push.unregister');

            Route::get('/me', [Shop\AuthController::class, 'me'])->name('me');
            Route::patch('/me', [Shop\AuthController::class, 'update'])->name('me.update');
            Route::put('/me/password', [Shop\AuthController::class, 'changePassword'])->name('me.password');
            Route::delete('/me', [Shop\AuthController::class, 'destroy'])->name('me.destroy');
            Route::post('/auth/logout', [Shop\AuthController::class, 'logout'])->name('auth.logout');
            Route::put('/garage', [Shop\VehicleController::class, 'setGarage'])->name('garage');
            Route::post('/stores/{sellerProfile}/follow', [Shop\SellerController::class, 'follow'])->name('store.follow');
            Route::post('/products/{id}/reviews', [Shop\EngagementController::class, 'writeReview'])->whereNumber('id')->name('product.review');
            Route::post('/products/{id}/questions', [Shop\EngagementController::class, 'ask'])->whereNumber('id')->name('product.ask');
            Route::post('/questions/{id}/answers', [Shop\EngagementController::class, 'answer'])->whereNumber('id')->name('questions.answer');
        });
    });
