<?php

namespace App\Jobs;

use App\Models\ShopifyConnection;
use App\Models\ShopifyProductLink;
use App\Services\Shopify\ShopifyAuthException;
use App\Services\Shopify\ShopifyClient;
use App\Services\Shopify\ShopifyProductSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Full sync: lists every product in the Shopify store and queues one sync per product (imports
 * queue, one at a time, so it stays inside Shopify's request budget). Products linked here that
 * Shopify no longer has (deleted while disconnected) are removed.
 */
class SyncShopifyStore implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct(public int $connectionId)
    {
        $this->onQueue('imports');
    }

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    public function handle(ShopifyProductSync $sync): void
    {
        $c = ShopifyConnection::find($this->connectionId);
        if (! $c || ! $c->isConnected()) {
            return;
        }

        try {
            $ids = $this->allProductIds($c);
        } catch (ShopifyAuthException $e) {
            $c->update(['status' => 'error', 'last_error' => $e->getMessage(), 'sync_status' => 'idle']);

            return;
        }

        // Gone from Shopify while we weren't listening.
        $stale = ShopifyProductLink::where('shopify_connection_id', $c->id)->whereNotIn('shopify_product_id', $ids ?: [0])->pluck('shopify_product_id');
        foreach ($stale as $sid) {
            $sync->remove($c, (int) $sid);
        }

        $c->update(['sync_status' => $ids ? 'running' : 'idle', 'sync_total' => count($ids), 'sync_done' => 0, 'last_error' => null]
            + ($ids ? [] : ['last_full_sync_at' => now()]));

        foreach ($ids as $id) {
            SyncShopifyProduct::dispatch($c->id, $id, 'product', true)->onQueue('imports');
        }
    }

    /** @return list<int> */
    private function allProductIds(ShopifyConnection $c): array
    {
        $client = ShopifyClient::for($c);
        $ids = [];
        $after = null;
        do {
            $page = $client->query('query($after: String) { products(first: 250, after: $after) { nodes { id } pageInfo { hasNextPage endCursor } } }', ['after' => $after])['products'] ?? [];
            foreach ($page['nodes'] ?? [] as $n) {
                $ids[] = ShopifyProductSync::numericId($n['id']);
            }
            $after = ($page['pageInfo']['hasNextPage'] ?? false) ? $page['pageInfo']['endCursor'] : null;
        } while ($after && count($ids) < 50_000);

        return array_values(array_filter(array_unique($ids)));
    }

    public function failed(?\Throwable $e): void
    {
        ShopifyConnection::whereKey($this->connectionId)->update([
            'sync_status' => 'idle',
            'last_error' => 'Full sync stopped: '.\Illuminate\Support\Str::limit((string) $e?->getMessage(), 300),
        ]);
    }
}
