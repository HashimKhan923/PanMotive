<?php

namespace App\Livewire\Seller;

use App\Jobs\ProcessBulkProductUpload;
use App\Models\Category;
use App\Services\Seller\ProductCsvImporter;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use League\Csv\Reader;

#[Layout('layouts.seller')]
class BulkUpload extends Component
{
    use WithFileUploads;

    public $csvFile;

    public ?int $defaultCategoryId = null;

    public bool $withImages = true;

    /** @var array{format:string, created:int, skipped:int, errors:array}|null */
    public ?array $result = null;

    public bool $queued = false;

    /** Files with more rows than this are processed on the queue instead of inline. */
    private const INLINE_ROW_LIMIT = 1500;

    // NB: not `upload()` — that name collides with Livewire's built-in $wire.upload().
    public function runImport(ProductCsvImporter $importer): void
    {
        $this->reset('result', 'queued');

        $this->validate([
            // Browsers report CSV MIME wildly inconsistently, so trust the extension.
            'csvFile' => 'required|file|max:10240|extensions:csv,txt',
            'defaultCategoryId' => 'nullable|integer|exists:categories,id',
        ], [
            'csvFile.extensions' => 'Upload a .csv file exported from Shopify or the Panmotive template.',
        ]);

        $key = 'bulk-upload:'.auth()->id();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('csvFile', 'Too many uploads — wait a minute and try again.');

            return;
        }
        RateLimiter::hit($key, 60);

        // The uploaded file is already on disk (livewire-tmp) — read it straight off.
        $tmpPath = $this->csvFile->getRealPath();

        $fallbackCategory = $this->defaultCategoryId ? (int) $this->defaultCategoryId : null;

        // Big file? Persist it and hand it to the queue.
        if ($this->rowCount($tmpPath) > self::INLINE_ROW_LIMIT) {
            $stored = $this->csvFile->store('bulk-uploads/'.auth()->id());
            ProcessBulkProductUpload::dispatch(auth()->id(), $stored, $fallbackCategory, $this->withImages);
            $this->queued = true;
            $this->dispatch('toast', message: 'Large file — importing in the background. You\'ll get a notification when it\'s done.');

            return;
        }

        try {
            $this->result = $importer->import(auth()->id(), $tmpPath, $fallbackCategory, $this->withImages);
        } catch (\Throwable $e) {
            report($e);
            $this->addError('csvFile', 'Could not read that file. Make sure it is a valid CSV with a header row.');

            return;
        }

        $this->csvFile = null;

        if ($this->result['created'] > 0) {
            $this->dispatch('toast', message: "{$this->result['created']} product(s) imported as drafts.");
        }
    }

    private function rowCount(string $absolute): int
    {
        try {
            $csv = Reader::createFromPath($absolute, 'r');
            $csv->setHeaderOffset(0);

            return count($csv);
        } catch (\Throwable) {
            return 0;
        }
    }

    public function render()
    {
        return view('livewire.seller.bulk-upload', [
            'categories' => Category::where('is_active', true)->orderBy('name')->get(['id', 'name', 'parent_id']),
        ]);
    }
}
