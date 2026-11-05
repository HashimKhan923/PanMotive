<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AdminTools;
use App\Http\Controllers\Controller;
use App\Services\AI\AiClient;
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
        ];
    }

    /** What still stands between this install and a safe launch. */
    private function launchChecks(): array
    {
        return [
            ['Debug mode off', ! config('app.debug'), 'Debug pages show secrets to visitors.'],
            ['Real email sending', ! in_array(config('mail.default'), ['log', 'array'], true), 'Emails are only written to a log file right now.'],
            ['Sales tax collected', app(\App\Services\Tax\SalesTaxService::class)->ready(), 'US marketplaces must normally collect it. Needs Stripe Tax active, your state registrations, and SALES_TAX_COLLECTING=true.'],
            ['Terms and Privacy pages published', \App\Models\Page::where('is_published', true)->count() >= 2, 'Add them in Content → Pages.'],
        ];
    }
}
