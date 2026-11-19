<?php

namespace App\Jobs;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A deleted listing's photos come off the media disk (Cloudflare R2 in production).
 *
 * Listings are soft-deleted, and the media library keeps a soft-deleted model's files — so
 * without this, every deleted listing's photos would stay in the bucket forever. Removing them
 * is several storage requests per photo, so it runs on the queue instead of in the seller's click.
 */
class DeleteProductPhotos implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public int $productId) {}

    public function handle(): void
    {
        $product = Product::withTrashed()->find($this->productId);

        // Gone entirely (force-deleted — the media library already removed the files), or brought back.
        if (! $product || ! $product->trashed()) {
            return;
        }

        // Each delete removes the original, its WebP sizes and its folder from the disk.
        $product->media()->get()->each->delete();
    }
}
