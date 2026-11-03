<?php

use App\Http\Controllers\Api\Seller\OrderController;
use App\Http\Controllers\Api\Seller\ProductController;
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
