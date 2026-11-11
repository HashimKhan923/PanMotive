<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AdminTools;
use App\Http\Controllers\Controller;
use App\Services\AI\AiClient;
use App\Support\Heartbeat;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Is everything running, and is it set up? Shows only whether a key exists, never its value.
 */
class SystemController extends Controller
{
    use AdminTools;

    public function index()
    {
        return view('admin.system.index', [
            'checks' => $this->checks(),
            'integrations' => $this->integrations(),
            'launch' => $this->launchChecks(),
            'failed' => DB::table('failed_jobs')->latest('failed_at')->take(25)->get()->map(function ($job) {
                $payload = json_decode($job->payload, true);
                $job->name = class_basename($payload['displayName'] ?? 'Job');
                $job->error = \Illuminate\Support\Str::of($job->exception)->before("\n")->limit(160);

                return $job;
            }),
            'failedCount' => DB::table('failed_jobs')->count(),
            'env' => [
                'Environment' => app()->environment(), 'Laravel' => app()->version(), 'PHP' => PHP_VERSION,
                'Database' => DB::connection()->getDriverName(), 'Queue' => config('queue.default'),
                'Cache' => config('cache.default'), 'Mail' => config('mail.default'), 'Search' => config('scout.driver'),
            ],
        ]);
    }

    public function clearCache()
    {
        Artisan::call('cache:clear');
        Artisan::call('view:clear');
        $this->audit('Cleared caches');

        return back()->with('success', 'Caches cleared. The next page loads may be a little slower.');
    }

    public function retryJob(string $uuid)
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        $this->audit('Retried failed job', null, ['uuid' => $uuid]);

        return back()->with('success', 'Job sent back to the queue.');
    }

    public function forgetJob(string $uuid)
    {
        Artisan::call('queue:forget', ['id' => $uuid]);
        $this->audit('Deleted failed job', null, ['uuid' => $uuid]);

        return back()->with('success', 'Failed job deleted.');
    }

    /** @return list<array{label: string, ok: bool, detail: string}> */
    private function checks(): array
    {
        return [
            $this->check('Database', function () {
                $start = microtime(true);
                DB::select('SELECT 1');

                return round((microtime(true) - $start) * 1000, 1).' ms';
            }),
            $this->check('Cache', function () {
                Cache::put('system.ping', 'ok', 10);

                return Cache::get('system.ping') === 'ok' ? 'Reading and writing' : throw new \RuntimeException('Could not read back');
            }),
            $this->check('Queue', fn () => number_format(Queue::size()).' jobs waiting'),
            $this->check('Background worker', function () {
                if (config('queue.default') === 'sync') {
                    return 'Jobs run straight away, no worker needed';
                }

                return $this->recently('queue-worker', 15, 'Seller payouts, bulk uploads and queued emails wait until it runs.');
            }),
            $this->check('Scheduler', fn () => $this->recently('scheduler', 15, 'Delivery auto-confirm, cart reminders and backups will not happen.')),
            $this->check('Backups', fn () => $this->backupStatus()),
            $this->check('Stripe webhooks arriving', fn () => $this->webhookStatus('stripe-webhook', 'Stripe', filled(config('services.stripe.webhook_secret')))),
            $this->check('EasyPost webhooks arriving', fn () => $this->webhookStatus('easypost-webhook', 'EasyPost', filled(config('services.easypost.webhook_secret')))),
            $this->check('Failed jobs', function () {
                $count = DB::table('failed_jobs')->count();

                return $count ? throw new \RuntimeException("{$count} failed") : 'None';
            }),
            $this->check('File storage', function () {
                $free = disk_free_space(storage_path());

                return is_writable(storage_path('app')) ? round($free / 1073741824, 1).' GB free' : throw new \RuntimeException('Not writable');
            }),
            $this->check('Search engine', function () {
                if (config('scout.driver') !== 'meilisearch') {
                    return 'Database search';
                }

                try {
                    return Http::timeout(1)->get(rtrim((string) config('scout.meilisearch.host'), '/').'/health')->successful()
                        ? 'Meilisearch running'
                        : throw new \RuntimeException('down');
                } catch (\Throwable) {
                    throw new \RuntimeException('Meilisearch is not running. Search still works from the database, without typo tolerance.');
                }
            }),
        ];
    }

    /** OK only if this ran in the last few minutes. Throws, with what it means for the business, if not. */
    private function recently(string $heartbeat, int $minutes, string $impact): string
    {
        $seen = Heartbeat::lastSeen($heartbeat);

        if (! $seen) {
            throw new \RuntimeException("Not running. {$impact}");
        }

        if ($seen->lt(now()->subMinutes($minutes))) {
            throw new \RuntimeException('Stopped '.$seen->diffForHumans().". {$impact}");
        }

        return 'Last ran '.$seen->diffForHumans();
    }

    private function backupStatus(): string
    {
        $disk = config('backup.backup.destination.disks.0');
        $settings = config("filesystems.disks.{$disk}", []);

        if (! $settings || (($settings['driver'] ?? null) === 's3' && (blank($settings['key'] ?? null) || blank($settings['bucket'] ?? null)))) {
            throw new \RuntimeException('Backup storage is not set up. There is no backup copy away from this server.');
        }

        $success = Heartbeat::lastSeen('backup-success');
        $failed = Heartbeat::lastSeen('backup-failed');

        if ($failed && (! $success || $failed->gt($success))) {
            throw new \RuntimeException('The last backup failed '.$failed->diffForHumans().'.');
        }

        if (! $success) {
            throw new \RuntimeException('Storage is set up, but no backup has finished yet.');
        }

        if ($success->lt(now()->subHours(48))) {
            throw new \RuntimeException('The last backup was '.$success->diffForHumans().'. They should run daily.');
        }

        return 'Last backup '.$success->diffForHumans();
    }

    /** A quiet shop can go a long time without a webhook, so an old one isn't a problem. Never having had one is. */
    private function webhookStatus(string $heartbeat, string $service, bool $configured): string
    {
        $seen = Heartbeat::lastSeen($heartbeat);

        if ($seen) {
            return 'Last arrived '.$seen->diffForHumans();
        }

        throw new \RuntimeException($configured
            ? "None has arrived yet. Send a test from {$service}. If it never shows here, Cloudflare may be blocking it."
            : "{$service} is not set up yet, so none can arrive.");
    }

    private function check(string $label, callable $probe): array
    {
        try {
            return ['label' => $label, 'ok' => true, 'detail' => (string) $probe()];
        } catch (\Throwable $e) {
            return ['label' => $label, 'ok' => false, 'detail' => \Illuminate\Support\Str::limit($e->getMessage(), 120)];
        }
    }

    private function integrations(): array
    {
        return [
            ['Stripe payments', filled(config('services.stripe.secret')), 'Takes card payments and pays sellers.'],
            ['Stripe webhooks', filled(config('services.stripe.webhook_secret')), 'Confirms payments. Orders stay unpaid without it.'],
            ['EasyPost shipping', filled(config('services.easypost.key')), 'Buys real shipping labels.'],
            ['AI features', app(AiClient::class)->isConfigured(), 'Listing writer and smart search.'],
            ['Offsite Ads feed key', filled(config('marketplace.offsite_ads.feed_key')), 'Protects your product feed URLs.'],
            ['Postmark email', $this->postmarkConnected(), 'Delivers receipts, shipping updates and password resets. Replies still go to your Google inbox (support@).'],
        ];
    }

    private function postmarkConnected(): bool
    {
        return config('mail.default') === 'postmark'
            && filled(config('services.postmark.key') ?? config('services.postmark.token'));
    }

    private function hasRealPostalAddress(): bool
    {
        $address = (string) config('marketplace.marketing.postal_address');

        // The placeholder set in config/marketplace.php.
        return filled($address) && ! str_contains($address, '2100 Commerce St');
    }

    /** What still stands between this install and a safe launch. */
    private function launchChecks(): array
    {
        return [
            ['Debug mode off', ! config('app.debug'), 'Debug pages show secrets to visitors.'],
            ['Real email sending', ! in_array(config('mail.default'), ['log', 'array'], true), 'Emails are only written to a log file right now.'],
            ['Connect Postmark for site emails', $this->postmarkConnected(), 'In Postmark: add panmotive.com under Sender Signatures and put its 2 DNS records (DKIM, Return-Path) on the domain, then request account approval. Then the server token goes in the server settings.'],
            ['Sales tax collected', app(\App\Services\Tax\SalesTaxService::class)->ready(), 'US marketplaces must normally collect it. Needs Stripe Tax active, your state registrations, and SALES_TAX_COLLECTING=true.'],
            ['Terms and Privacy pages published', \App\Models\Page::where('is_published', true)->count() >= 2, 'Add them in Content → Pages.'],
            ['Real postal address in marketing emails', $this->hasRealPostalAddress(), 'US law requires one. Emails show a placeholder right now. Add yours in Settings → Marketing rules.'],
        ];
    }
}
