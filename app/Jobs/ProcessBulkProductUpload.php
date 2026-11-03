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
use Illuminate\Support\Facades\Storage;

class ProcessBulkProductUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public function __construct(
        public int $sellerId,
        public string $path,
        public ?int $defaultCategoryId = null,
        public bool $withImages = true,
    ) {}

    public function handle(ProductCsvImporter $importer): void
    {
        try {
            $result = $importer->import(
                $this->sellerId,
                Storage::path($this->path),
                $this->defaultCategoryId,
                $this->withImages,
            );
        } finally {
            Storage::delete($this->path);
        }

        User::find($this->sellerId)?->notify(
            new BulkUploadCompleted($result['created'], $result['errors'], $result['skipped'] ?? 0),
        );
    }
}
