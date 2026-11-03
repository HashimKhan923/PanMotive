<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Services\Seller\InventoryService;

/**
 * Keeps on-hand stock in sync with orders: takes quantity out when an order is
 * placed, puts it back when the line is cancelled or payment fails.
 *
 * Both directions are idempotent via order_items.stock_committed_at, so a
 * retried Stripe webhook or a double-clicked cancel can never double-count.
 */
class OrderStockService
{
    public function __construct(private InventoryService $inventory) {}

    public function commit(Order $order): void
    {
        foreach ($order->items()->whereNull('stock_committed_at')->get() as $item) {
            $this->move($item, -$item->quantity, 'sale', "Order {$order->order_number}");
            $item->forceFill(['stock_committed_at' => now()])->save();
        }
    }

    public function release(OrderItem $item, string $note): void
    {
        if ($item->stock_committed_at === null) {
            return;
        }

        $this->move($item, $item->quantity, 'correction', $note);
        $item->forceFill(['stock_committed_at' => null])->save();
    }

    public function releaseOrder(Order $order, string $note): void
    {
        foreach ($order->items as $item) {
            $this->release($item, $note);
        }
    }

    private function move(OrderItem $item, int $delta, string $reason, string $note): void
    {
        $product = $item->product;
        if (! $product) {
            return; // listing hard-deleted since — nothing left to adjust.
        }

        // A variant deleted since the order was placed: nothing left to move.
        $variantId = $item->product_variant_id && ProductVariant::whereKey($item->product_variant_id)->exists()
            ? $item->product_variant_id
            : null;
        if ($item->product_variant_id && ! $variantId) {
            return;
        }

        $this->inventory->adjust($product, $delta, $reason, [
            'variant_id' => $variantId,
            'reference' => $item->order?->order_number,
            'note' => $note,
            'system' => true,
        ]);
    }
}
