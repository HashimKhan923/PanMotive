<?php

namespace App\Livewire\Seller;

use App\Jobs\ProcessBulkProductUpload;
use App\Models\Category;
use App\Services\Seller\ProductCsvImporter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Bulk upload: a product file from any platform (Shopify, WooCommerce, Amazon, eBay, Etsy, Wix,
 * BigCommerce, Magento, Walmart, our template or a home-made sheet). The file uploads with a
 * progress bar, then imports on the queue while this page polls the job's progress.
 */
#[Layout('layouts.seller')]
class BulkUpload extends Component
{
    use WithFileUploads;

    public $csvFile;

    public ?int $defaultCategoryId = null;

    public bool $withImages = true;

    /** The running/finished import this page is showing. */
    public ?string $importId = null;

    /** @var array{status:string, stage?:string, done?:int, total?:int, message?:string} */
    public array $progress = [];

    /** @var array{format:string, created:int, skipped:int, errors:array, uncategorised?:int, variants?:int, fitted?:int, headers?:array}|null */
    public ?array $result = null;

    public function mount(): void
    {
        // Came back while an import is still running? Pick it up again.
        if ($id = session('bulk-import-id')) {
            $this->importId = $id;
            $this->refreshProgress();
        }
    }

    // NB: not `upload()` — that name collides with Livewire's built-in $wire.upload().
    public function runImport(): void
    {
        $this->reset('result', 'progress', 'importId');

        $this->validate([
            // Browsers report CSV MIME types wildly inconsistently, so trust the extension.
            'csvFile' => 'required|file|max:12288|extensions:csv,txt,tsv,xlsx',
            'defaultCategoryId' => 'nullable|integer|exists:categories,id',
        ], [
            'csvFile.extensions' => 'Upload a .csv, .tsv or Excel .xlsx product file (Shopify, WooCommerce, Amazon, eBay, Etsy…).',
            'csvFile.max' => 'That file is bigger than 12 MB. Split it into smaller files and upload them one by one.',
        ]);

        $key = 'bulk-upload:'.auth()->id();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('csvFile', 'Too many uploads — wait a minute and try again.');

            return;
        }
        RateLimiter::hit($key, 60);

        $this->importId = (string) Str::uuid();
        $stored = $this->csvFile->storeAs('bulk-uploads/'.auth()->id(), $this->importId.'.csv', 'local');
        $this->csvFile = null;
        // The import runs as the queue worker's user (same group): let it read and then delete the file.
        $absolute = \Storage::disk('local')->path($stored);
        @chmod(dirname($absolute), 02770);
        @chmod($absolute, 0660);

        Cache::put(ProcessBulkProductUpload::progressKey(auth()->id(), $this->importId), ['status' => 'queued'], now()->addDay());
        session(['bulk-import-id' => $this->importId]);

        // Own queue + worker, so photo downloads (default queue) start while the import is still running.
        ProcessBulkProductUpload::dispatch(
            auth()->id(), $stored, $this->defaultCategoryId ? (int) $this->defaultCategoryId : null, $this->withImages, $this->importId,
        )->onQueue('imports');

        $this->refreshProgress();
    }

    /** Polled by the page every second while the import runs. */
    public function refreshProgress(): void
    {
        if (! $this->importId) {
            return;
        }
        $state = Cache::get(ProcessBulkProductUpload::progressKey(auth()->id(), $this->importId));
        if (! $state) {
            $this->reset('importId', 'progress');
            session()->forget('bulk-import-id');

            return;
        }
        $this->progress = $state;

        if ($state['status'] === 'done') {
            $this->result = $state['result'];
            session()->forget('bulk-import-id');
            if ($this->result['created'] > 0) {
                $this->dispatch('toast', message: "{$this->result['created']} product(s) imported as drafts.");
            }
        } elseif ($state['status'] === 'cancelled') {
            session()->forget('bulk-import-id');
        } elseif ($state['status'] === 'failed') {
            session()->forget('bulk-import-id');
            $this->addError('csvFile', $state['message'] ?? 'The import failed.');
        }
    }

    public function cancelImport(): void
    {
        if (! $this->importId) {
            return;
        }
        $key = ProcessBulkProductUpload::progressKey(auth()->id(), $this->importId);
        $state = Cache::get($key);
        if (($state['status'] ?? null) === 'queued') {
            // Not started yet: the job will see this and stop straight away.
            Cache::put($key, ['status' => 'cancelled', 'done' => 0], now()->addDay());
        }
        Cache::put(ProcessBulkProductUpload::cancelKey(auth()->id(), $this->importId), true, now()->addDay());
        $this->progress = ['status' => 'cancelling'] + ($state ?? []);
    }

    public function startOver(): void
    {
        $this->reset('result', 'progress', 'importId', 'csvFile');
        session()->forget('bulk-import-id');
    }

    public function render()
    {
        return view('livewire.seller.bulk-upload', [
            'categories' => Category::where('is_active', true)->orderBy('parent_id')->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'parent_id']),
            'formats' => ProductCsvImporter::FORMATS,
        ]);
    }
}
