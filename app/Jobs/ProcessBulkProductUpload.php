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

    /** Set by the page's Cancel button; the import stops at the next product. */
    public static function cancelKey(int $sellerId, string $importId): string
    {
        return self::progressKey($sellerId, $importId).':cancel';
    }

    public function handle(ProductCsvImporter $importer): void
    {
        if ($this->importId && Cache::pull(self::cancelKey($this->sellerId, $this->importId))) {
            Storage::disk('local')->delete($this->path); // cancelled while waiting in line
            $this->put(['status' => 'cancelled', 'done' => 0]);

            return;
        }

        $lastWrite = 0.0;
        $created = 0;
        $progress = function (string $stage, int $done, int $total) use (&$lastWrite, &$created) {
            if ($stage === 'products') {
                $created = $done;
            }
            // At most a few cache writes a second, plus every stage change and the end.
            $now = microtime(true);
            if ($now - $lastWrite >= 0.5 || $done === 0 || $done >= $total) {
                if ($this->importId && Cache::pull(self::cancelKey($this->sellerId, $this->importId))) {
                    throw new ImportCancelled;
                }
                $this->put(['status' => 'running', 'stage' => $stage, 'done' => $done, 'total' => $total, 'photos' => $this->withImages]);
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
        } catch (ImportCancelled) {
            $this->put(['status' => 'cancelled', 'done' => $created]);

            return;
        } catch (\Throwable $e) {
            report($e);
            $this->put(['status' => 'failed', 'message' => $e instanceof \InvalidArgumentException
                ? $e->getMessage()   // a reason the seller can act on ("old .xls file", "not a spreadsheet")
                : 'We could not read that file. Make sure it is a CSV, TSV or Excel (.xlsx) export with a header row.']);

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
