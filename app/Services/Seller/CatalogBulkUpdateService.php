<?php

namespace App\Services\Seller;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;

/**
 * "Export → edit in Excel → upload" for price, stock and status. Rows are matched to the
 * seller's own listings (and options) by id or SKU; a preview shows every change before
 * anything is written. Stock changes go through InventoryService, so they're logged.
 * A blank cell means "leave as is".
 */
class CatalogBulkUpdateService
{
    public const HEADERS = ['listing_id', 'option_id', 'sku', 'title', 'option', 'price', 'sale_price', 'quantity', 'status'];

    public const STATUSES = ['active', 'draft', 'archived'];

    public function __construct(private InventoryService $inventory) {}

    /** One row per simple listing, one row per option for listings sold by option. */
    public function exportRows(int $sellerId): Collection
    {
        return Product::where('seller_id', $sellerId)
            ->with(['activeVariants' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('title')->get()
            ->flatMap(function (Product $p) {
                if ($p->activeVariants->isEmpty()) {
                    return [[$p->id, '', $p->sku, $p->title, '', $this->money($p->price), $this->money($p->sale_price), $p->quantity, $p->status]];
                }

                return $p->activeVariants->map(fn (ProductVariant $v) => [
                    $p->id, $v->id, $v->sku ?: $p->sku, $p->title, $v->label,
                    // An option without its own price inherits the listing's.
                    $this->money($v->price ?? $p->price), $this->money($v->sale_price), $v->quantity, $p->status,
                ])->all();
            });
    }

    /**
     * Read an uploaded sheet and work out exactly what would change.
     *
     * @return array{changes: list<array>, errors: list<string>, unchanged: int}
     */
    public function preview(int $sellerId, string $path): array
    {
        $csv = Reader::createFromPath($path);
        $csv->setHeaderOffset(0);
        $headers = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $csv->getHeader());

        if (! array_intersect(['listing_id', 'sku'], $headers)) {
            return ['changes' => [], 'errors' => ['The file needs a "listing_id" or "sku" column — start from the export so the columns match.'], 'unchanged' => 0];
        }

        $products = Product::where('seller_id', $sellerId)->with('activeVariants')->get();
        $byId = $products->keyBy('id');
        $bySku = $products->filter(fn ($p) => filled($p->sku))->keyBy(fn ($p) => strtolower($p->sku));
        $variantsBySku = $products->flatMap->activeVariants->filter(fn ($v) => filled($v->sku))->keyBy(fn ($v) => strtolower($v->sku));

        $changes = [];
        $errors = [];
        $unchanged = 0;
        $line = 1;

        foreach ($csv->getRecords($csv->getHeader()) as $raw) {
            $line++;
            $row = [];
            foreach ($raw as $k => $v) {
                $row[strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $k)))] = trim((string) $v);
            }
            if (! array_filter($row, fn ($v) => $v !== '')) {
                continue; // blank line
            }

            // Match: explicit ids first, then SKU (option SKU before listing SKU).
            $product = null;
            $variant = null;
            if (($row['listing_id'] ?? '') !== '') {
                $product = $byId->get((int) $row['listing_id']);
                if (! $product) {
                    $errors[] = "Row {$line}: listing #{$row['listing_id']} isn't one of yours.";

                    continue;
                }
                if (($row['option_id'] ?? '') !== '') {
                    $variant = $product->activeVariants->firstWhere('id', (int) $row['option_id']);
                    if (! $variant) {
                        $errors[] = "Row {$line}: option #{$row['option_id']} doesn't belong to \"{$product->title}\".";

                        continue;
                    }
                }
            } elseif (($row['sku'] ?? '') !== '') {
                $sku = strtolower($row['sku']);
                if ($variant = $variantsBySku->get($sku)) {
                    $product = $byId->get($variant->product_id);
                } elseif (! $product = $bySku->get($sku)) {
                    $errors[] = "Row {$line}: no listing with SKU \"{$row['sku']}\".";

                    continue;
                }
            } else {
                $errors[] = "Row {$line}: needs a listing_id or sku.";

                continue;
            }

            if (! $variant && $product->activeVariants->isNotEmpty()) {
                $errors[] = "Row {$line}: \"{$product->title}\" is sold by option — use one row per option (see the export).";

                continue;
            }

            $current = [
                'price' => (float) ($variant ? ($variant->price ?? $product->price) : $product->price),
                'sale_price' => ($variant ? $variant->sale_price : $product->sale_price) !== null ? (float) ($variant ? $variant->sale_price : $product->sale_price) : null,
                'quantity' => (int) ($variant ? $variant->quantity : $product->quantity),
                'status' => $product->status,
            ];

            $new = $current;
            $rowErrors = [];

            if (($row['price'] ?? '') !== '') {
                $price = $this->parseMoney($row['price']);
                $price === null || $price <= 0 ? $rowErrors[] = 'price must be more than $0' : $new['price'] = $price;
            }
            if (array_key_exists('sale_price', $row) && $row['sale_price'] !== '') {
                // "0" or "none" clears a sale price.
                if (in_array(strtolower($row['sale_price']), ['0', 'none', 'clear', '-'], true)) {
                    $new['sale_price'] = null;
                } else {
                    $sale = $this->parseMoney($row['sale_price']);
                    $sale === null || $sale <= 0 ? $rowErrors[] = 'sale_price must be a number' : $new['sale_price'] = $sale;
                }
            }
            if ($new['sale_price'] !== null && $new['sale_price'] >= $new['price']) {
                $rowErrors[] = 'sale_price must be lower than price';
            }
            if (($row['quantity'] ?? '') !== '') {
                if (! preg_match('/^\d+$/', $row['quantity']) || (int) $row['quantity'] > 1000000) {
                    $rowErrors[] = 'quantity must be a whole number (0 or more)';
                } else {
                    $new['quantity'] = (int) $row['quantity'];
                }
            }
            if (($row['status'] ?? '') !== '') {
                $status = strtolower($row['status']);
                in_array($status, self::STATUSES, true) ? $new['status'] = $status : $rowErrors[] = 'status must be active, draft or archived';
            }

            $label = $product->title.($variant ? " — {$variant->label}" : '');
            if ($rowErrors) {
                $errors[] = "Row {$line} ({$label}): ".implode('; ', $rowErrors).'.';

                continue;
            }

            $diff = array_filter(['price', 'sale_price', 'quantity', 'status'], fn ($f) => $new[$f] !== $current[$f]);
            if (! $diff) {
                $unchanged++;

                continue;
            }

            $changes[] = [
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'label' => $label,
                'fields' => array_values($diff),
                'from' => $current,
                'to' => $new,
            ];
        }

        // A listing/option changed by two rows is ambiguous — refuse rather than guess.
        $keys = collect($changes)->map(fn ($c) => $c['product_id'].'-'.($c['variant_id'] ?? 0));
        foreach ($keys->duplicates()->unique() as $dup) {
            $errors[] = 'The same listing/option appears more than once ('.collect($changes)->first(fn ($c) => $c['product_id'].'-'.($c['variant_id'] ?? 0) === $dup)['label'].') — keep one row for it.';
        }
        if ($keys->duplicates()->isNotEmpty()) {
            $changes = collect($changes)->reject(fn ($c) => $keys->duplicates()->contains($c['product_id'].'-'.($c['variant_id'] ?? 0)))->values()->all();
        }

        return ['changes' => $changes, 'errors' => $errors, 'unchanged' => $unchanged];
    }

    /** Write a previewed change set. Re-checks ownership; stock moves are logged. */
    public function apply(int $sellerId, array $changes): int
    {
        $applied = 0;
        $reviewRequired = (bool) config('marketplace.listings.review_required');

        DB::transaction(function () use ($sellerId, $changes, $reviewRequired, &$applied) {
            foreach ($changes as $c) {
                $product = Product::where('seller_id', $sellerId)->find($c['product_id']);
                if (! $product) {
                    continue;
                }
                $variant = $c['variant_id'] ? ProductVariant::where('product_id', $product->id)->find($c['variant_id']) : null;
                if ($c['variant_id'] && ! $variant) {
                    continue;
                }
                $to = $c['to'];

                if (in_array('price', $c['fields'], true) || in_array('sale_price', $c['fields'], true)) {
                    ($variant ?? $product)->update(['price' => $to['price'], 'sale_price' => $to['sale_price']]);
                }

                if (in_array('quantity', $c['fields'], true)) {
                    $current = (int) ($variant ? $variant->fresh()->quantity : $product->fresh()->quantity);
                    if ($to['quantity'] !== $current) {
                        $this->inventory->adjust($product, $to['quantity'] - $current, 'correction', [
                            'variant_id' => $variant?->id,
                            'note' => 'Spreadsheet update',
                        ]);
                    }
                }

                if (in_array('status', $c['fields'], true)) {
                    // Same rule as the Publish button: a never-published listing needs review when the platform requires it.
                    $status = $to['status'] === 'active' && $reviewRequired && ! $product->published_at ? 'pending_review' : $to['status'];
                    $product->forceFill([
                        'status' => $status,
                        'published_at' => $status === 'active' ? ($product->published_at ?? now()) : $product->published_at,
                    ])->save();
                }

                $applied++;
            }
        });

        return $applied;
    }

    private function parseMoney(string $v): ?float
    {
        // "-5" must be an error, not silently become $5.
        if (str_contains($v, '-')) {
            return null;
        }
        // Allow "$1,299.00" style input from spreadsheets.
        $clean = preg_replace('/[\s$,]/', '', $v);

        return $clean === '' || ! is_numeric($clean) ? null : round((float) $clean, 2);
    }

    private function money($v): string
    {
        return $v === null ? '' : number_format((float) $v, 2, '.', '');
    }
}
