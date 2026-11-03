<?php

namespace App\Services\Seller;

use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Notifications\LowStockAlert;
use App\Support\SellerContext;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function ensureDefaultLocation(User $seller): InventoryLocation
    {
        $existing = InventoryLocation::where('seller_id', $seller->id)->orderByDesc('is_default')->first();
        if ($existing) {
            return $existing;
        }

        return InventoryLocation::create([
            'seller_id' => $seller->id,
            'name' => 'Main warehouse',
            'code' => 'MAIN',
            'is_default' => true,
        ]);
    }

    /**
     * Apply a stock change and record it in the movement log.
     * When the product has per-location rows, quantity is recomputed from them;
     * otherwise products.quantity is adjusted directly.
     */
    public function adjust(Product $product, int $delta, string $reason, array $opts = []): StockMovement
    {
        abort_unless(in_array($reason, StockMovement::REASONS, true), 422);

        $variantId = $opts['variant_id'] ?? null;
        $locationId = $opts['location_id'] ?? null;

        // A product sold by option (colour, side…) is stocked per option — the change has to
        // say which one, or it would move a number buyers never actually buy from.
        if ($variantId === null && $product->hasVariants()) {
            abort(422, 'This listing is stocked per option — choose which option to adjust.');
        }
        $variant = $variantId ? ProductVariant::where('product_id', $product->id)->findOrFail($variantId) : null;

        return DB::transaction(function () use ($product, $delta, $reason, $opts, $variant, $variantId, $locationId) {
            $hasLocations = ProductStock::where('product_id', $product->id)->exists()
                || ($locationId !== null);

            if ($hasLocations) {
                $defaultId = InventoryLocation::where('seller_id', $product->seller_id)
                    ->orderByDesc('is_default')->value('id')
                    ?? $this->ensureDefaultLocation($product->seller)->id;
                $locationId ??= $defaultId;

                // First time this listing/option is tracked by location: its existing stock is
                // sitting in the default location. Seed that, or the location totals would start
                // from zero and the stock would effectively disappear.
                $tracked = ProductStock::where('product_id', $product->id)->where('product_variant_id', $variantId)->exists();
                $existing = $variant ? (int) $variant->quantity : (int) $product->quantity;
                if (! $tracked && $existing > 0) {
                    ProductStock::create([
                        'product_id' => $product->id,
                        'product_variant_id' => $variantId,
                        'inventory_location_id' => $defaultId,
                        'quantity' => $existing,
                    ]);
                }

                $row = ProductStock::firstOrCreate(
                    ['product_id' => $product->id, 'product_variant_id' => $variantId, 'inventory_location_id' => $locationId],
                    ['quantity' => 0],
                );
                $row->quantity = max(0, $row->quantity + $delta);
                $row->save();

                if ($variant) {
                    // Option stock = its rows across every location; saving it re-totals the product.
                    $variant->quantity = (int) ProductStock::where('product_variant_id', $variant->id)->sum('quantity');
                    $variant->save();
                } else {
                    $product->quantity = (int) ProductStock::where('product_id', $product->id)
                        ->whereNull('product_variant_id')->sum('quantity');
                    $product->save();
                }
            } elseif ($variant) {
                $variant->quantity = max(0, (int) $variant->quantity + $delta);
                $variant->save(); // re-totals the product via ProductVariant::booted()
            } else {
                $product->quantity = max(0, (int) $product->quantity + $delta);
                $product->save();
            }

            $product->refresh();

            // Sales / cancellations are system events — don't log them under whoever triggered
            // them (that's the buyer at checkout).
            $actor = ($opts['system'] ?? false) ? null : SellerContext::actor();

            $movement = StockMovement::create([
                'product_id' => $product->id,
                'product_variant_id' => $variantId,
                'inventory_location_id' => $locationId,
                'delta' => $delta,
                'reason' => $reason,
                'reference' => $opts['reference'] ?? null,
                'note' => $opts['note'] ?? null,
                'actor_id' => $actor?->id,
                'actor_name' => $actor?->name ?? (($opts['system'] ?? false) ? 'System' : null),
                'balance_after' => $variant ? $variant->fresh()->quantity : $product->quantity,
            ]);

            // Clear a stale low-stock flag once restocked above the reorder point.
            if ($delta > 0 && $product->reorder_point !== null && $product->quantity > $product->reorder_point) {
                $product->forceFill(['low_stock_notified_at' => null])->save();
            }

            return $movement;
        });
    }

    /** Set an absolute on-hand count for a product/location, logging the difference. */
    public function setLevel(Product $product, int $target, array $opts = []): ?StockMovement
    {
        $current = (int) $product->quantity;
        if ($opts['location_id'] ?? false) {
            $current = (int) ProductStock::where('product_id', $product->id)
                ->where('inventory_location_id', $opts['location_id'])
                ->where('product_variant_id', $opts['variant_id'] ?? null)
                ->value('quantity');
        }

        $delta = $target - $current;
        if ($delta === 0) {
            return null;
        }

        return $this->adjust($product, $delta, $opts['reason'] ?? 'correction', $opts);
    }

    /** @return \Illuminate\Support\Collection<int,Product> */
    public function lowStock(int $sellerId)
    {
        return Product::where('seller_id', $sellerId)
            ->whereNotNull('reorder_point')
            ->whereColumn('quantity', '<=', 'reorder_point')
            ->where('status', '!=', 'archived')
            ->orderBy('quantity')
            ->get();
    }

    /** Notify sellers whose products dropped to or below their reorder point. Returns notifications sent. */
    public function sweepLowStock(): int
    {
        $sent = 0;

        Product::whereNotNull('reorder_point')
            ->whereColumn('quantity', '<=', 'reorder_point')
            ->whereNull('low_stock_notified_at')
            ->where('status', 'active')
            ->with('seller')
            ->chunkById(200, function ($products) use (&$sent) {
                foreach ($products as $product) {
                    $product->seller?->notify(new LowStockAlert($product));
                    $product->forceFill(['low_stock_notified_at' => now()])->save();
                    $sent++;
                }
            });

        return $sent;
    }
}
