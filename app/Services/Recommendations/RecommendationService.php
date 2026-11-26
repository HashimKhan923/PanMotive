<?php

namespace App\Services\Recommendations;

use App\Enums\OrderStatus;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rule-based on purpose: PDP recommendations need to be instant, so no
 * per-request AI. AI is reserved for offline work (SEO, listing drafts).
 */
class RecommendationService
{
    public function forVehicle(int $vehicleId, int $limit = 8): Collection
    {
        return Product::listed()->original()
            ->whereHas('fitments', fn ($q) => $q->where('vehicles.id', $vehicleId))
            ->inRandomOrder()->limit($limit)->get();
    }

    /**
     * Products really bought in the same order as this one (paid orders only), that also suit the
     * same vehicle: they fit a vehicle this product fits, or fit any vehicle. Never the same kind of
     * part (two tail lights aren't bought "together", they're alternatives).
     */
    public function frequentlyBoughtWith(int $productId, int $limit = 6): Collection
    {
        $product = Product::find($productId);
        if (! $product) {
            return new Collection;
        }
        $notBought = [OrderStatus::Placed->value, OrderStatus::Cancelled->value, OrderStatus::Refunded->value];

        $ids = DB::table('order_items as oi1')
            ->join('order_items as oi2', 'oi1.order_id', '=', 'oi2.order_id')
            ->where('oi1.product_id', $productId)
            ->where('oi2.product_id', '!=', $productId)
            ->whereNotIn('oi1.status', $notBought)
            ->whereNotIn('oi2.status', $notBought)
            ->select('oi2.product_id', DB::raw('COUNT(DISTINCT oi1.order_id) as freq'))
            ->groupBy('oi2.product_id')
            ->orderByDesc('freq')
            ->limit(50)
            ->pluck('product_id')
            ->all();
        if (! $ids) {
            return new Collection;
        }

        $vehicleIds = $this->vehicleIds($product);

        return Product::whereIn('id', $ids)->listed()->original()
            ->when($product->category_id, fn ($q) => $q->where('category_id', '!=', $product->category_id))
            ->when($vehicleIds, fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('fitments', fn ($f) => $f->whereIn('vehicles.id', $vehicleIds))
                ->orWhereDoesntHave('fitments')))
            ->get()
            ->sortBy(fn ($p) => array_search($p->id, $ids))
            ->take($limit)
            ->values();
    }

    /**
     * The "goes with this" box under a product: real "bought together" items when there are any;
     * otherwise other kinds of parts for the same vehicle (same store first), under an honest heading;
     * otherwise nothing, rather than parts for some other car.
     *
     * @return array{title: string, items: Collection}
     */
    public function companions(Product $product, int $limit = 4): array
    {
        $bought = $this->frequentlyBoughtWith($product->id, $limit);
        if ($bought->isNotEmpty()) {
            return ['title' => 'Frequently bought together', 'items' => $bought];
        }

        $vehicleIds = $this->vehicleIds($product);
        if (! $vehicleIds) {
            return ['title' => 'Frequently bought together', 'items' => new Collection];
        }

        $items = Product::listed()->original()
            ->where('id', '!=', $product->id)
            ->when($product->category_id, fn ($q) => $q->where('category_id', '!=', $product->category_id))
            ->whereHas('fitments', fn ($f) => $f->whereIn('vehicles.id', $vehicleIds))
            ->withCount(['fitments as shared_fit' => fn ($f) => $f->whereIn('vehicles.id', $vehicleIds)])
            ->orderByRaw('CASE WHEN seller_id = ? THEN 0 ELSE 1 END', [$product->seller_id])
            ->orderByDesc('shared_fit')
            ->orderByDesc('id')
            ->take($limit)
            ->get();

        $label = $product->vehicleLabel();

        return ['title' => $label && ! str_contains($label, '+') ? "More for your {$label}" : 'More parts that fit', 'items' => $items];
    }

    /** @return list<int> */
    private function vehicleIds(Product $product): array
    {
        return $product->fitments()->pluck('vehicles.id')->all();
    }

    /** "Customers who viewed this also viewed" — co-view affinity from the view log. */
    public function alsoViewed(int $productId, int $limit = 6): Collection
    {
        $since = now()->subDays(60);

        $viewerKeys = DB::table('product_views')
            ->where('product_id', $productId)
            ->where('viewed_at', '>=', $since)
            ->distinct()
            ->pluck('viewer_key');

        if ($viewerKeys->isEmpty()) {
            return new Collection;
        }

        $ids = DB::table('product_views')
            ->whereIn('viewer_key', $viewerKeys)
            ->where('product_id', '!=', $productId)
            ->where('viewed_at', '>=', $since)
            ->select('product_id', DB::raw('COUNT(*) as freq'))
            ->groupBy('product_id')
            ->orderByDesc('freq')
            ->limit($limit)
            ->pluck('product_id')
            ->all();

        if (! $ids) {
            return new Collection;
        }

        return Product::whereIn('id', $ids)->listed()->original()->with('brand')
            ->get()->sortBy(fn ($p) => array_search($p->id, $ids))->values();
    }

    public function personalizedForBuyer(int $buyerId, int $limit = 8): Collection
    {
        $purchasedCategoryIds = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('orders.buyer_id', $buyerId)
            ->pluck('products.category_id')->unique();

        if ($purchasedCategoryIds->isEmpty()) {
            return Product::listed()->original()->inRandomOrder()->limit($limit)->get();
        }

        return Product::listed()->original()
            ->whereIn('category_id', $purchasedCategoryIds)
            ->inRandomOrder()->limit($limit)->get();
    }
}
