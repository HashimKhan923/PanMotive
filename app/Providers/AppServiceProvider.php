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

        // What the admin health page reads to show the worker and backups are really running.
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Queue\Events\Looping::class, fn () => \App\Support\Heartbeat::beat('queue-worker'));
        \Illuminate\Support\Facades\Event::listen(\Spatie\Backup\Events\BackupWasSuccessful::class, fn () => \App\Support\Heartbeat::beat('backup-success'));
        \Illuminate\Support\Facades\Event::listen(\Spatie\Backup\Events\BackupHasFailed::class, fn () => \App\Support\Heartbeat::beat('backup-failed'));

        $this->configureRateLimiters();
        $this->configureEmails();

        // Nginx hosts like CloudPanel treat any ".js" address as a file on disk and answer 404 before
        // Laravel sees it. Without this script, Livewire and Alpine never start and no button works.
        Livewire::setScriptRoute(fn ($handle) => \Illuminate\Support\Facades\Route::get('/livewire/script', $handle));

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

    /** Account emails Laravel sends itself, in Panmotive's words, plus the welcome email and failure alerts. */
    private function configureEmails(): void
    {
        \Illuminate\Auth\Notifications\ResetPassword::toMailUsing(function ($notifiable, string $token) {
            $url = route('password.reset', ['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()]);
            $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

            return \App\Notifications\Messages\PanmotiveMail::to($notifiable)
                ->subject('Reset your Panmotive password')
                ->preheader('This link works for '.$minutes.' minutes.')
                ->line('We got a request to reset the password for your Panmotive account.')
                ->action('Choose a new password', $url)
                ->line("This link works for {$minutes} minutes and can only be used once.")
                ->line('Didn’t ask for this? You can ignore this email. Your password stays the same, and no one can change it without this link.')
                ->reason('For your security, Panmotive will never ask for your password by email.');
        });

        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Registered::class, function ($event) {
            try {
                $event->user->notify(new \App\Notifications\WelcomeToPanmotive);
            } catch (\Throwable $e) {
                report($e); // a mail hiccup must never stop someone signing up
            }
        });

        // An email that still fails after every retry shows up in the admin alert inbox,
        // so a broken mail setup is noticed instead of silently losing receipts.
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Queue\Events\JobFailed::class, function ($event) {
            $payload = $event->job->payload();
            if (($payload['displayName'] ?? '') !== \Illuminate\Notifications\SendQueuedNotifications::class) {
                return;
            }
            try {
                $command = unserialize($payload['data']['command'] ?? '');
                if (! in_array('mail', (array) ($command->channels ?? []), true)) {
                    return;
                }
                \App\Models\AdminAlert::create([
                    'type' => 'email_failed',
                    'message' => 'An email could not be sent ('.class_basename($command->notification).'): '.\Illuminate\Support\Str::limit($event->exception->getMessage(), 300),
                ]);
            } catch (\Throwable) {
                // never let alerting itself break the worker
            }
        });
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
