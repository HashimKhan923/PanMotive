<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        \App\Support\PlatformSettings::apply();
        // Queue workers run for days: without this they'd never see a setting changed after they started.
        \Illuminate\Support\Facades\Queue::before(fn () => \App\Support\PlatformSettings::apply());

        $this->configureRateLimiters();

        // Keep the "act as store owner" rebinding alive across Livewire updates
        // in the seller portal (Livewire re-runs only persistent middleware).
        Livewire::addPersistentMiddleware([
            \App\Http\Middleware\EnsureUserIsSeller::class,
            \App\Http\Middleware\EnsureSellerApproved::class,
            \App\Http\Middleware\ResolveSellerActor::class,
            // Re-check the page's team permission on every button press, not just the first load.
            \App\Http\Middleware\SellerAbility::class,
        ]);
    }

    /**
     * Named limiters for every abuse- or cost-sensitive write path
     * (Phases 2-8). Applied via ->middleware('throttle:<name>') on routes,
     * or RateLimiter::attempt('<name>:'.$key) inside Livewire actions.
     */
    private function configureRateLimiters(): void
    {
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        // Seller REST API: per token (hashed — the raw token never becomes a cache key), else per IP.
        RateLimiter::for('seller-api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->bearerToken() ? 'tok:'.hash('sha256', $request->bearerToken()) : 'ip:'.$request->ip()));

        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(10)
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('ai-chat', fn (Request $request) => Limit::perMinute(20)
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('bulk-upload', fn (Request $request) => Limit::perMinute(3)
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
