<?php

namespace App\Services\Shopify;

use App\Jobs\FetchProductImages;
use App\Models\Brand;
use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ShopifyConnection;
use App\Models\ShopifyProductLink;
use App\Services\Seller\CategoryResolver;
use App\Services\Seller\Import\FitmentMatcher;
use App\Support\Html\DescriptionSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Keeps one Panmotive product in step with one Shopify product.
 *
 * Shopify is the source of truth for: title, description, brand (vendor), SKU, prices, stock,
 * weight, options/variants, photos and live/draft status. Panmotive keeps what sellers set here and
 * Shopify doesn't know about: category (detected once), vehicle fitment (added to, never removed),
 * shipping policy, offers, etc.
 */
class ShopifyProductSync
{
    public const PRODUCT_FIELDS = <<<'GQL'
        fragment P on Product {
          id title descriptionHtml vendor productType status tags
          media(first: 50) { nodes { ... on MediaImage { id image { url } } } }
          variants(first: 100) { nodes {
            id title sku price compareAtPrice inventoryQuantity position
            selectedOptions { name value }
            image { url }
            inventoryItem { tracked measurement { weight { unit value } } }
          } }
        }
        GQL;

    /** Stock shown for a variant whose inventory Shopify doesn't track ("always in stock"). */
    public const UNTRACKED_STOCK = 99;

    public function __construct(
        private CategoryResolver $categories,
        private FitmentMatcher $fitment,
        private DescriptionSanitizer $sanitizer,
    ) {}

    public static function numericId(string|int|null $gid): ?int
    {
        return $gid !== null && preg_match('/(\d+)$/', (string) $gid, $m) ? (int) $m[1] : null;
    }

    /** Fetch the product's current state from Shopify and apply it (a missing product is a delete). */
    public function syncById(ShopifyConnection $c, int $shopifyProductId): string
    {
        $data = ShopifyClient::for($c)->query('query($id: ID!) { product(id: $id) { ...P } } '.self::PRODUCT_FIELDS, [
            'id' => "gid://shopify/Product/{$shopifyProductId}",
        ]);

        return $data['product'] ?? null
            ? $this->apply($c, $data['product'])
            : $this->remove($c, $shopifyProductId);
    }

    /** Stock changed on an inventory item → find its product and sync that. */
    public function syncByInventoryItem(ShopifyConnection $c, int $inventoryItemId): string
    {
        $data = ShopifyClient::for($c)->query('query($id: ID!) { inventoryItem(id: $id) { variant { product { id } } } }', [
            'id' => "gid://shopify/InventoryItem/{$inventoryItemId}",
        ]);
        $productId = self::numericId($data['inventoryItem']['variant']['product']['id'] ?? null);

        return $productId ? $this->syncById($c, $productId) : 'skipped';
    }

    /**
     * @param  array<string,mixed>  $sp  a Product node from the GraphQL API
     * @return string created|updated|unchanged
     */
    public function apply(ShopifyConnection $c, array $sp): string
    {
        $shopifyId = self::numericId($sp['id']);
        $hash = sha1(json_encode($sp));
        $link = ShopifyProductLink::where('shopify_connection_id', $c->id)->where('shopify_product_id', $shopifyId)->first();
        $product = $link?->product;

        if ($product && $link->payload_hash === $hash && ! $product->trashed()) {
            return 'unchanged'; // Shopify often sends several identical updates for one save
        }

        return DB::transaction(function () use ($c, $sp, $shopifyId, $hash, $link, $product) {
            $variants = collect($sp['variants']['nodes'] ?? [])->sortBy('position')->values();
            $hasOptions = $variants->count() > 1
                || ($variants->count() === 1 && ! $this->isDefaultVariant($variants[0]));
            $first = $variants->first() ?? [];

            // Product price = the cheapest option; a higher "compare at" price means it's on sale.
            $cheapest = $variants->sortBy(fn ($v) => (float) $v['price'])->first() ?? [];
            [$price, $sale] = $this->prices($cheapest);
            $title = Str::limit(trim((string) $sp['title']) ?: 'Untitled product', 190, '');
            $isNew = ! $product || $product->trashed();

            $fields = [
                'title' => $title,
                'description' => $this->sanitizer->sanitize($sp['descriptionHtml'] ?? null, 20000),
                'brand_id' => $this->brandId($sp['vendor'] ?? null),
                'sku' => $hasOptions ? ($first['sku'] ?? null ?: null) : (($first['sku'] ?? '') ?: null),
                'price' => $price,
                'sale_price' => $sale,
                'quantity' => $variants->sum(fn ($v) => $this->stock($v)),
                'weight_lbs' => $this->weightLbs($first['inventoryItem']['measurement']['weight'] ?? null),
            ];

            if ($isNew) {
                $product = Product::create($fields + [
                    'seller_id' => $c->seller_id,
                    'slug' => Str::slug(Str::limit($title, 120, '')).'-'.Str::random(6),
                    'category_id' => $this->categoryFor($sp, $title),
                    'condition' => 'new',
                    'status' => 'draft',
                ]);
                $result = 'created';
            } else {
                $titleChanged = $product->title !== $title;
                $product->update($fields);
                $result = 'updated';
            }

            $this->applyStatus($product, (string) ($sp['status'] ?? 'DRAFT'));
            if ($isNew || ($titleChanged ?? false)) {
                $this->addFitment($product, $title, (array) ($sp['tags'] ?? []));
            }
            $this->syncVariants($product, $hasOptions ? $variants->all() : []);
            $this->syncPhotos($product, $sp, $hasOptions ? $variants->all() : []);

            ShopifyProductLink::updateOrCreate(
                ['shopify_connection_id' => $c->id, 'shopify_product_id' => $shopifyId],
                ['product_id' => $product->id, 'payload_hash' => $hash, 'synced_at' => now()],
            );

            return $result;
        });
    }

    /** Deleted in Shopify → deleted here (photos go too). A listing with orders is archived instead. */
    public function remove(ShopifyConnection $c, int $shopifyProductId): string
    {
        $link = ShopifyProductLink::where('shopify_connection_id', $c->id)->where('shopify_product_id', $shopifyProductId)->first();
        $product = $link?->product;
        if (! $product) {
            $link?->delete();

            return 'skipped';
        }

        if (OrderItem::where('product_id', $product->id)->exists()) {
            $product->update(['status' => 'archived']);
            $link->delete();

            return 'archived';
        }

        $link->delete();
        if (! $product->trashed()) {
            $product->delete(); // queues removal of its photos from storage
        }

        return 'deleted';
    }

    /* ------------------------------------------------------------------ */

    private function isDefaultVariant(array $v): bool
    {
        $opts = $v['selectedOptions'] ?? [];

        return $opts === [] || (count($opts) === 1 && strcasecmp((string) $opts[0]['value'], 'Default Title') === 0);
    }

    /** @return array{0: float, 1: ?float} [price, sale price] */
    private function prices(array $v): array
    {
        $price = round((float) ($v['price'] ?? 0), 2);
        $compare = round((float) ($v['compareAtPrice'] ?? 0), 2);
        if ($compare > $price && $price > 0) {
            return [$compare, $price];
        }

        return [max($price, 0.01), null];
    }

    private function stock(array $v): int
    {
        if (! ($v['inventoryItem']['tracked'] ?? true)) {
            return self::UNTRACKED_STOCK;
        }

        return max(0, min((int) ($v['inventoryQuantity'] ?? 0), 1_000_000));
    }

    private function weightLbs(?array $w): ?float
    {
        $value = (float) ($w['value'] ?? 0);
        if ($value <= 0) {
            return null;
        }

        return round($value * match (strtoupper((string) ($w['unit'] ?? 'POUNDS'))) {
            'GRAMS' => 0.00220462,
            'KILOGRAMS' => 2.20462,
            'OUNCES' => 0.0625,
            default => 1.0,
        }, 2) ?: null;
    }

    private function brandId(?string $vendor): ?int
    {
        $name = trim((string) $vendor);
        if ($name === '' || Str::length($name) > 80) {
            return null;
        }

        return Brand::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name])->id;
    }

    /** Product type and tags first, then the title (AI only when nothing else matches), else Other Parts. */
    private function categoryFor(array $sp, string $title): int
    {
        $hints = array_values(array_filter([(string) ($sp['productType'] ?? ''), ...array_map('strval', (array) ($sp['tags'] ?? []))], 'strlen'));
        if ($id = $this->categories->resolve(...$hints, ...[$title])) {
            return $id;
        }
        $ai = $this->categories->resolveMany([$title], true);
        if ($id = $ai[Str::lower(trim($title))] ?? null) {
            return $id;
        }

        return (int) (Category::where('slug', 'other-parts')->value('id') ?? Category::whereNull('parent_id')->orderBy('id')->value('id'));
    }

    private function addFitment(Product $product, string $title, array $tags): void
    {
        $ids = $this->fitment->fromText($title);
        foreach ($tags as $tag) {
            array_push($ids, ...$this->fitment->fromText((string) $tag));
        }
        if ($ids) {
            $product->fitments()->syncWithoutDetaching(array_values(array_unique($ids)));
        }
    }

    /** Shopify Active → live (or review, if the platform requires it); Draft/Archived/other → hidden. */
    private function applyStatus(Product $product, string $shopifyStatus): void
    {
        if ($shopifyStatus === 'ACTIVE') {
            if (in_array($product->status, ['active', 'pending_review'], true)) {
                return;
            }
            $needsReview = config('marketplace.listings.review_required') && ! $product->published_at;
            $product->forceFill([
                'status' => $needsReview ? 'pending_review' : 'active', // a staff take-down still goes to review (model hook)
                'published_at' => $product->published_at ?? ($needsReview ? null : now()),
            ])->save();

            return;
        }

        $target = $shopifyStatus === 'ARCHIVED' ? 'archived' : 'draft';
        if ($product->status !== $target) {
            $product->forceFill(['status' => $target])->save();
        }
    }

    /** One Panmotive option per Shopify variant, matched by Shopify's variant id. */
    private function syncVariants(Product $product, array $variants): void
    {
        $keep = [];
        foreach (array_values($variants) as $i => $v) {
            $opts = collect($v['selectedOptions'] ?? [])->mapWithKeys(fn ($o) => [(string) $o['name'] => (string) $o['value']]);
            $label = Str::limit($opts->map(fn ($val, $name) => strcasecmp($name, 'Title') === 0 ? $val : "{$name}: {$val}")->implode(' / ') ?: (string) $v['title'], 120, '');
            [$price, $sale] = $this->prices($v);
            $sid = self::numericId($v['id']);
            $keep[] = $sid;
            $product->variants()->updateOrCreate(['shopify_variant_id' => $sid], [
                'label' => $label,
                'options' => $opts->all(),
                'sku' => ($v['sku'] ?? '') ?: null,
                'price' => $price,
                'sale_price' => $sale,
                'quantity' => $this->stock($v),
                'weight_lbs' => $this->weightLbs($v['inventoryItem']['measurement']['weight'] ?? null),
                'is_active' => true,
                'sort_order' => $i,
            ]);
        }

        // Options removed in Shopify (and, when it no longer has options, every Shopify option).
        $product->variants()->whereNotNull('shopify_variant_id')->whereNotIn('shopify_variant_id', $keep ?: [0])->get()->each->delete();
        $product->syncStockFromVariants();
    }

    /**
     * Photos follow Shopify: new ones are downloaded (photos queue), removed ones are deleted from
     * storage, and the order matches. Photos the seller added on Panmotive itself are left alone.
     */
    private function syncPhotos(Product $product, array $sp, array $variants): void
    {
        $wanted = [];
        foreach ($sp['media']['nodes'] ?? [] as $m) {
            if ($url = $m['image']['url'] ?? null) {
                $wanted[self::photoKey($url)] = $url;
            }
        }
        $variantPhotos = [];
        foreach ($variants as $v) {
            if ($url = $v['image']['url'] ?? null) {
                $wanted[self::photoKey($url)] ??= $url;
                $variantPhotos[self::numericId($v['id'])] = self::photoKey($url);
            }
        }

        $have = $product->getMedia('images')->filter(fn (Media $m) => filled($m->getCustomProperty('source_url')))
            ->keyBy(fn (Media $m) => self::photoKey((string) $m->getCustomProperty('source_url')));

        foreach ($have as $key => $media) {
            if (! isset($wanted[$key])) {
                $media->delete(); // removed in Shopify
            }
        }

        $missing = array_values(array_diff_key($wanted, $have->all()));
        if ($missing) {
            // Variant photo links are set by the photo job once each photo is in.
            $labels = [];
            foreach ($product->variants()->whereNotNull('shopify_variant_id')->get() as $pv) {
                if (($key = $variantPhotos[$pv->shopify_variant_id] ?? null) && in_array($wanted[$key], $missing, true)) {
                    $labels[$pv->label] = $wanted[$key];
                }
            }
            FetchProductImages::dispatch($product->id, $missing, $labels)->afterCommit();
        }

        // Photos already here: keep Shopify's order and the variant links.
        $order = array_flip(array_keys($wanted));
        foreach ($have as $key => $media) {
            if (isset($order[$key]) && $media->order_column !== $order[$key] + 1) {
                Media::whereKey($media->id)->update(['order_column' => $order[$key] + 1]);
            }
        }
        foreach ($product->variants()->whereNotNull('shopify_variant_id')->get() as $pv) {
            $key = $variantPhotos[$pv->shopify_variant_id] ?? null;
            $mediaId = $key ? $have->get($key)?->id : null;
            if ($mediaId && $pv->media_id !== $mediaId) {
                $pv->update(['media_id' => $mediaId]);
            }
        }
    }

    /** Shopify photo links carry "?v=…" — the file path alone identifies the photo. */
    public static function photoKey(string $url): string
    {
        return strtolower(strtok($url, '?'));
    }
}
