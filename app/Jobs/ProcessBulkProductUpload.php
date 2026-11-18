<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\BulkUploadCompleted;
use App\Services\Seller\ProductCsvImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Runs a seller's product file import on the queue and keeps its progress in the cache, so the
 * Bulk upload page can show a live progress bar (and the seller can leave and come back).
 */
class ProcessBulkProductUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1; // a half-finished import must not run again and create duplicates

    public function __construct(
        public int $sellerId,
        public string $path,
        public ?int $defaultCategoryId = null,
        public bool $withImages = true,
        public ?string $importId = null,
    ) {}

    public static function progressKey(int $sellerId, string $importId): string
    {
        return "bulk-import:{$sellerId}:{$importId}";
    }

    public function handle(ProductCsvImporter $importer): void
    {
        $lastWrite = 0.0;
        $progress = function (string $stage, int $done, int $total) use (&$lastWrite) {
            // At most a few cache writes a second, plus every stage change and the end.
            $now = microtime(true);
            if ($now - $lastWrite >= 0.5 || $done === 0 || $done >= $total) {
                $this->put(['status' => 'running', 'stage' => $stage, 'done' => $done, 'total' => $total]);
                $lastWrite = $now;
            }
        };

        try {
            $result = $importer->import(
                $this->sellerId,
                Storage::disk('local')->path($this->path),
                $this->defaultCategoryId,
                $this->withImages,
                true,
                $progress,
            );
            $this->put(['status' => 'done', 'result' => $result]);
        } catch (\Throwable $e) {
            report($e);
            $this->put(['status' => 'failed', 'message' => 'We could not read that file. Make sure it is a CSV or TSV export with a header row.']);

            return;
        } finally {
            Storage::disk('local')->delete($this->path);
        }

        User::find($this->sellerId)?->notify(
            new BulkUploadCompleted($result['created'], $result['errors'], $result['skipped'] ?? 0),
        );
    }

    public function failed(?\Throwable $e): void
    {
        // Timed out or killed: tell the page instead of leaving the bar spinning forever.
        $this->put(['status' => 'failed', 'message' => 'The import stopped before it finished. Products created so far are in your drafts.']);
    }

    private function put(array $state): void
    {
        if ($this->importId) {
            Cache::put(self::progressKey($this->sellerId, $this->importId), $state, now()->addDay());
        }
    }
}
