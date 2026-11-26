<?php

namespace App\Services\Seller;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\SupplyConnection;
use App\Models\User;
use App\Notifications\AccountNotice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Supply Network (like Shopify Collective, inside Panmotive): a supplier lets connected resellers list
 * its products in their own store. The reseller's listing mirrors the supplier's product (price,
 * stock, photos, fitment, options); orders for it go to the supplier to ship; at payout the reseller
 * earns the agreed margin out of the supplier's share.
 */
class SupplyNetworkService
{
    /** The lowest margin a supplier may offer, so reselling is always worth a reseller's while. */
    public const MIN_MARGIN = 10.0;

    public const MAX_MARGIN = 60.0;

    // ---------------------------------------------------------------- connections

    /** Suppliers a reseller can browse: stores that switched the network on (not itself). */
    public function suppliers(?int $exceptSellerId = null): Builder
    {
        return SellerProfile::query()
            ->where('supply_enabled', true)
            ->where('status', 'approved')
            ->when($exceptSellerId, fn ($q) => $q->where('user_id', '!=', $exceptSellerId))
            ->whereHas('user', fn ($q) => $q->where('is_active', true));
    }

    /** Products a supplier offers to resellers. */
    public function offered(int $supplierId): Builder
    {
        return Product::listed()->original()->where('seller_id', $supplierId)->where('supply_excluded', false);
    }

    public function connection(int $supplierId, int $resellerId): ?SupplyConnection
    {
        return SupplyConnection::where('supplier_id', $supplierId)->where('reseller_id', $resellerId)->first();
    }

    /** A reseller asks to resell a supplier's products. Auto-approved when the supplier allows it. */
    public function request(User $reseller, User $supplier, ?string $message = null): SupplyConnection
    {
        $profile = $supplier->sellerProfile;
        if ($reseller->id === $supplier->id || ! $profile?->supply_enabled || $profile->status !== 'approved') {
            throw ValidationException::withMessages(['supplier' => 'This store isn\'t offering its products for resale.']);
        }
        if (! $reseller->sellerProfile || $reseller->sellerProfile->status !== 'approved') {
            throw ValidationException::withMessages(['supplier' => 'Your store needs to be approved before you can resell.']);
        }

        $existing = $this->connection($supplier->id, $reseller->id);
        if ($existing && in_array($existing->status, ['pending', 'approved'], true)) {
            return $existing;
        }
        if ($existing?->status === 'declined' && $existing->decided_at?->gt(now()->subDays(30))) {
            throw ValidationException::withMessages(['supplier' => 'This supplier declined your request recently. You can ask again after '.$existing->decided_at->addDays(30)->format('M j').'.']);
        }

        $auto = (bool) $profile->supply_auto_approve;
        $conn = SupplyConnection::updateOrCreate(
            ['supplier_id' => $supplier->id, 'reseller_id' => $reseller->id],
            [
                'status' => $auto ? 'approved' : 'pending',
                'margin_pct' => $this->clampMargin((float) $profile->supply_margin_pct),
                'message' => $message ? Str::limit(trim($message), 500, '') : null,
                'decided_at' => $auto ? now() : null,
            ],
        );

        $name = $reseller->sellerProfile->business_name;
        $supplier->notify(new AccountNotice(
            $auto ? "{$name} is now reselling your products" : "{$name} wants to resell your products",
            $auto ? "{$name} joined your Supply Network at a {$conn->margin_pct}% margin. You ship their orders; they earn the margin."
                : "{$name} asked to list your products in their store. Approve or decline in Supply Network.",
            route('seller.supply.index', ['tab' => 'resellers']),
        ));

        return $conn;
    }

    public function approve(SupplyConnection $conn, ?float $marginPct = null): void
    {
        $conn->update([
            'status' => 'approved',
            'margin_pct' => $this->clampMargin($marginPct ?? (float) $conn->margin_pct),
            'decided_at' => now(),
        ]);
        $supplierName = $conn->supplier->sellerProfile?->business_name ?? 'The supplier';
        $conn->reseller->notify(new AccountNotice(
            "{$supplierName} approved you as a reseller",
            "You can now add {$supplierName}'s products to your store and earn {$conn->margin_pct}% on every sale. They ship the orders.",
            route('seller.supply.index', ['tab' => 'find', 'supplier' => $conn->supplier_id]),
        ));
    }

    public function decline(SupplyConnection $conn): void
    {
        $conn->update(['status' => 'declined', 'decided_at' => now()]);
        $supplierName = $conn->supplier->sellerProfile?->business_name ?? 'The supplier';
        $conn->reseller->notify(new AccountNotice("{$supplierName} declined your reseller request", 'You can browse other suppliers in Supply Network.', route('seller.supply.index', ['tab' => 'find'])));
    }

    /**
     * Either side ends the partnership. The reseller's copies stop being buyable at once (the listing
     * rule checks the connection); orders already placed still ship and pay out as agreed.
     */
    public function end(SupplyConnection $conn, User $by): void
    {
        $conn->update(['status' => 'ended', 'decided_at' => now()]);
        $body = 'Resold listings from this partnership are no longer for sale. Orders already placed still ship and pay out as agreed.';
        if (! in_array($by->id, [$conn->supplier_id, $conn->reseller_id], true)) {
            // Ended by Panmotive staff: both stores are told.
            foreach ([$conn->supplier, $conn->reseller] as $store) {
                $store?->notify(new AccountNotice('Panmotive ended a Supply Network partnership', $body.' Contact support if you have questions.', route('seller.supply.index')));
            }

            return;
        }
        $other = $by->id === $conn->supplier_id ? $conn->reseller : $conn->supplier;
        $byName = $by->sellerProfile?->business_name ?? 'The other store';
        $other?->notify(new AccountNotice("{$byName} ended your Supply Network partnership", $body, route('seller.supply.index')));
    }

    /** A supplier changes one reseller's margin. Applies to new orders only. */
    public function setMargin(SupplyConnection $conn, float $marginPct): void
    {
        $conn->update(['margin_pct' => $this->clampMargin($marginPct)]);
    }

    public function clampMargin(float $pct): float
    {
        return round(min(self::MAX_MARGIN, max(self::MIN_MARGIN, $pct)), 2);
    }

    /** The reseller's margin on a resold listing right now (null when not connected). */
    public function marginFor(Product $listing): ?float
    {
        $source = $listing->source;
        if (! $source) {
            return null;
        }
        $conn = SupplyConnection::where('supplier_id', $source->seller_id)->where('reseller_id', $listing->seller_id)
            ->where('status', 'approved')->first();

        return $conn ? (float) $conn->margin_pct : null;
    }

    // ---------------------------------------------------------------- listings

    /** Add a supplier's product to a reseller's store: a mirrored listing, live straight away. */
    public function addToStore(User $reseller, Product $source): Product
    {
        if ($source->source_product_id) {
            $source = $source->source;   // always copy the original, never a copy of a copy
        }
        $source = $source->fresh() ?? $source;   // every column as stored (defaults included)
        $conn = $this->connection($source->seller_id, $reseller->id);
        if (! $conn?->isApproved()) {
            throw ValidationException::withMessages(['product' => 'Connect with this supplier first.']);
        }
        if ($source->supply_excluded || ! $this->offered($source->seller_id)->whereKey($source->id)->exists()) {
            throw ValidationException::withMessages(['product' => 'This product isn\'t offered for resale right now.']);
        }

        $existing = Product::withTrashed()->where('seller_id', $reseller->id)->where('source_product_id', $source->id)->first();
        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            if ($existing->status !== 'active') {
                $existing->forceFill(['status' => 'active', 'published_at' => $existing->published_at ?? now()])->save();
            }
            $this->sync($existing);

            return $existing;
        }

        return DB::transaction(function () use ($reseller, $source) {
            $copy = $this->asSync(fn () => Product::create(
                collect($source->only(Product::SUPPLY_SYNCED))->all() + [
                    'seller_id' => $reseller->id,
                    'source_product_id' => $source->id,
                    'slug' => Str::slug(Str::limit($source->title, 110, '')).'-'.Str::lower(Str::random(6)),
                    'status' => 'active',
                    'published_at' => now(),
                    'accepts_offers' => false,
                    'subscription_enabled' => false,
                ]
            ));
            $this->syncVariants($copy, $source);
            $this->syncFitments($copy, $source);

            return $copy;
        });
    }

    /** A reseller takes a resold listing down (soft delete — past orders keep their link). */
    public function removeFromStore(Product $copy): void
    {
        abort_unless($copy->source_product_id, 422);
        $copy->delete();
    }

    /** Bring one copy fully in line with its supplier's product. */
    public function sync(Product $copy): void
    {
        $source = $copy->source()->withTrashed()->first();
        if (! $source) {
            return;
        }
        $this->asSync(function () use ($copy, $source) {
            $copy->forceFill($source->only(Product::SUPPLY_SYNCED));
            $copy->accepts_offers = false;
            $copy->subscription_enabled = false;
            $copy->save();
        });
        $this->syncVariants($copy, $source);
        $this->syncFitments($copy, $source);
    }

    /** Everything about every copy of a supplier product (queued after a supplier edit). */
    public function syncAllFrom(Product $source): void
    {
        foreach ($source->resoldCopies()->get() as $copy) {
            $this->sync($copy);
        }
    }

    /** Price and stock on every copy right away, so no buyer pays an old price or buys stock that's gone. */
    public function pushQuick(Product $source): void
    {
        $values = $source->only(['price', 'sale_price', 'sale_starts_at', 'deal_ends_at', 'quantity']);
        $this->asSync(function () use ($source, $values) {
            foreach ($source->resoldCopies()->get() as $copy) {
                $copy->forceFill($values);
                if ($copy->isDirty()) {
                    $copy->save();
                }
            }
        });
    }

    /** A supplier option's price/stock on every copy's matching option, and the copies' totals. */
    public function pushVariant(ProductVariant $source): void
    {
        $this->asSync(function () use ($source) {
            foreach (ProductVariant::where('source_variant_id', $source->id)->get() as $copy) {
                $copy->forceFill($source->only(['label', 'options', 'sku', 'price', 'sale_price', 'quantity', 'weight_lbs', 'is_active', 'sort_order', 'media_id']));
                if ($copy->isDirty()) {
                    $copy->save();   // re-totals the copy's stock
                }
            }
        });
    }

    private function syncVariants(Product $copy, Product $source): void
    {
        $this->asSync(function () use ($copy, $source) {
            $sourceVariants = ProductVariant::where('product_id', $source->id)->get();
            $mine = ProductVariant::where('product_id', $copy->id)->get()->keyBy('source_variant_id');
            foreach ($sourceVariants as $sv) {
                $fields = $sv->only(['label', 'options', 'sku', 'price', 'sale_price', 'quantity', 'weight_lbs', 'is_active', 'sort_order', 'media_id']);
                $row = $mine->get($sv->id) ?? new ProductVariant(['product_id' => $copy->id, 'source_variant_id' => $sv->id]);
                $row->forceFill($fields + ['product_id' => $copy->id, 'source_variant_id' => $sv->id]);
                if ($row->isDirty() || ! $row->exists) {
                    $row->save();
                }
            }
            // Options the supplier deleted: switch them off (old orders may still point at them).
            ProductVariant::where('product_id', $copy->id)
                ->whereNotIn('source_variant_id', $sourceVariants->pluck('id')->all() ?: [0])
                ->update(['is_active' => false, 'quantity' => 0]);
            $copy->refresh()->syncStockFromVariants();
        });
    }

    private function syncFitments(Product $copy, Product $source): void
    {
        $rows = DB::table('product_fitments')->where('product_id', $source->id)->get(['vehicle_id', 'position', 'notes']);
        $copy->fitments()->sync($rows->mapWithKeys(fn ($r) => [$r->vehicle_id => ['position' => $r->position, 'notes' => $r->notes]])->all());
    }

    private function asSync(callable $fn): mixed
    {
        $was = Product::$supplySyncing;
        Product::$supplySyncing = true;
        try {
            return $fn();
        } finally {
            Product::$supplySyncing = $was;
        }
    }
}
