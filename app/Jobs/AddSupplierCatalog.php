<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\User;
use App\Notifications\AccountNotice;
use App\Services\Seller\SupplyNetworkService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Supply Network: add every product a supplier offers to a reseller's store (skipping ones already there). */
class AddSupplierCatalog implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    /** @param bool $notify tell the reseller when done (when it ran in the background) */
    public function __construct(public int $resellerId, public int $supplierId, public bool $notify = true) {}

    public function uniqueId(): string
    {
        return "{$this->resellerId}-{$this->supplierId}";
    }

    public function handle(SupplyNetworkService $supply): void
    {
        $reseller = User::find($this->resellerId);
        if (! $reseller || ! $supply->connection($this->supplierId, $this->resellerId)?->isApproved()) {
            return;
        }
        $have = Product::where('seller_id', $this->resellerId)->whereNotNull('source_product_id')->pluck('source_product_id')->all();
        $added = 0;
        $supply->offered($this->supplierId)->whereNotIn('id', $have)->orderBy('id')
            ->chunkById(50, function ($products) use ($supply, $reseller, &$added) {
                foreach ($products as $p) {
                    try {
                        $supply->addToStore($reseller, $p);
                        $added++;
                    } catch (\Illuminate\Validation\ValidationException) {
                        // no longer offered since the count — skip it
                    }
                }
            });

        if ($this->notify && $added > 0) {
            $name = User::find($this->supplierId)?->sellerProfile?->business_name ?? 'the supplier';
            $reseller->notify(new AccountNotice("{$added} products added to your store", "Every product {$name} offers is now in your store. Price, stock and photos update by themselves.", route('seller.supply.index', ['tab' => 'listings'])));
        }
    }
}
