<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\Seller\SupplyNetworkService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** After a supplier edits a product, bring every reseller copy fully in line (options, fitment, text). */
class SyncSupplyListings implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** Many edits in a row (a bulk update) only need one sync. */
    public int $uniqueFor = 60;

    public function __construct(public int $productId) {}

    public function uniqueId(): string
    {
        return (string) $this->productId;
    }

    public function handle(SupplyNetworkService $supply): void
    {
        if ($source = Product::withTrashed()->find($this->productId)) {
            $supply->syncAllFrom($source);
        }
    }
}
