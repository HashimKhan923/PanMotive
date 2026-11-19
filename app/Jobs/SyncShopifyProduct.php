<?php

namespace App\Jobs;

use App\Models\ShopifyConnection;
use App\Services\Shopify\ShopifyAuthException;
use App\Services\Shopify\ShopifyProductSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Brings one product in line with Shopify. It always reads the product's *current* state from
 * Shopify, so webhooks arriving out of order or twice can't leave old data behind; a product
 * Shopify no longer has is removed here.
 */
class SyncShopifyProduct implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    /** @param  string  $kind  product|inventory_item */
    public function __construct(
        public int $connectionId,
        public int $shopifyId,
        public string $kind = 'product',
        public bool $partOfFullSync = false,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->connectionId}:{$this->kind}:{$this->shopifyId}:".($this->partOfFullSync ? 'full' : 'event');
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(ShopifyProductSync $sync): void
    {
        $c = ShopifyConnection::find($this->connectionId);
        if (! $c || ! $c->isConnected()) {
            return;
        }

        try {
            $this->kind === 'inventory_item'
                ? $sync->syncByInventoryItem($c, $this->shopifyId)
                : $sync->syncById($c, $this->shopifyId);
        } catch (ShopifyAuthException $e) {
            $c->update(['status' => 'error', 'last_error' => $e->getMessage(), 'sync_status' => 'idle']);

            return;
        } finally {
            if ($this->partOfFullSync) {
                $this->countDone($c);
            }
        }
    }

    public function failed(?\Throwable $e): void
    {
        if ($c = ShopifyConnection::find($this->connectionId)) {
            $c->update(['last_error' => 'Product '.$this->shopifyId.': '.\Illuminate\Support\Str::limit((string) $e?->getMessage(), 300)]);
        }
    }

    private function countDone(ShopifyConnection $c): void
    {
        $c->newQuery()->whereKey($c->id)->increment('sync_done');
        $c->refresh();
        if ($c->sync_status === 'running' && $c->sync_done >= $c->sync_total) {
            $c->update(['sync_status' => 'idle', 'last_full_sync_at' => now()]);
        }
    }
}
