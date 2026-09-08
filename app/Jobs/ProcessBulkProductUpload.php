<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\User;
use App\Notifications\BulkUploadCompleted;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Csv\Reader;

class ProcessBulkProductUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $sellerId,
        public string $path,
    ) {}

    public function handle(): void
    {
        $csv = Reader::createFromPath(Storage::path($this->path));
        $csv->setHeaderOffset(0);

        $created = 0;
        $errors = [];

        foreach ($csv->getRecords() as $offset => $record) {
            try {
                Product::create([
                    'seller_id' => $this->sellerId,
                    'title' => $record['title'],
                    'slug' => Str::slug($record['title']).'-'.Str::random(6),
                    'category_id' => $record['category_id'],
                    'sku' => $record['sku'] ?? null,
                    'part_number' => $record['part_number'] ?? null,
                    'oem_number' => $record['oem_number'] ?? null,
                    'condition' => $record['condition'] ?? 'new',
                    'price' => $record['price'],
                    'quantity' => $record['quantity'] ?? 0,
                    'description' => $record['description'] ?? null,
                    'status' => 'pending_review',
                ]);
                $created++;
            } catch (\Throwable $e) {
                $errors[] = "Row {$offset}: {$e->getMessage()}";
            }
        }

        User::find($this->sellerId)?->notify(new BulkUploadCompleted($created, $errors));
    }
}
