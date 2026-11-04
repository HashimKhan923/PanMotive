<?php

namespace App\Services\Seller;

use App\Jobs\FetchProductImages;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Str;
use League\Csv\Reader;

/**
 * Imports a product CSV for a seller. Auto-detects the Panmotive native format
 * and Shopify's product export, and falls back to fuzzy column matching for
 * anything else. Categories are auto-detected (keyword + fuzzy, with an optional
 * AI clean-up pass); a default is only used when nothing matches. Every row
 * lands as a draft the seller reviews before publishing.
 *
 * @phpstan-type ImportResult array{format:string, created:int, skipped:int, errors:list<string>, uncategorised:int}
 */
class ProductCsvImporter
{
    private int $sellerId;
    private ?int $defaultCategoryId;
    private bool $withImages;
    private bool $useAi;

    /** @var array<string,int> resolved hint => category id */
    private array $categoryMap = [];
    private int $uncategorised = 0;

    public function __construct(
        private CategoryResolver $categories,
        private \App\Support\Html\DescriptionSanitizer $sanitizer,
        private \App\Support\Html\DescriptionFormatter $formatter,
        private DescriptionAiStructurer $aiStructurer,
    ) {}

    /** @return ImportResult */
    public function import(int $sellerId, string $absolutePath, ?int $defaultCategoryId = null, bool $withImages = true, bool $useAi = true): array
    {
        $this->sellerId = $sellerId;
        $this->defaultCategoryId = $defaultCategoryId ?: (Category::where('slug', 'other-parts')->value('id') ?? Category::whereNull('parent_id')->orderBy('id')->value('id'));
        $this->withImages = $withImages;
        $this->useAi = $useAi;
        $this->categoryMap = [];
        $this->uncategorised = 0;

        $raw = (string) file_get_contents($absolutePath);
        // Strip UTF-8 BOM (Excel/Shopify love adding one — it corrupts the first header).
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

        $csv = Reader::createFromString($raw);
        $csv->setDelimiter($this->sniffDelimiter($raw));
        $csv->setHeaderOffset(0);

        $rawHeaders = array_map(fn ($h) => trim((string) $h), $csv->getHeader());
        $headers = array_map('strtolower', $rawHeaders);
        $records = iterator_to_array($csv->getRecords(), false);

        $format = $this->detectFormat($headers);

        $result = match ($format) {
            'shopify' => $this->importShopify($records),
            default => $this->importFlat($records, $format),
        };

        $result['headers'] = $rawHeaders;

        return $result;
    }

    /** Guess the column separator from the header line. */
    private function sniffDelimiter(string $raw): string
    {
        $firstLine = strtok($raw, "\r\n") ?: '';
        $counts = [];
        foreach ([',', ';', "\t", '|'] as $d) {
            $counts[$d] = substr_count($firstLine, $d);
        }
        arsort($counts);
        $best = array_key_first($counts);

        return $counts[$best] > 0 ? $best : ',';
    }

    /** Resolve every distinct category hint in one go before we start creating rows. */
    private function primeCategories(array $hintStrings): void
    {
        $hintStrings = array_values(array_filter(array_map('trim', $hintStrings)));
        if ($hintStrings) {
            $this->categoryMap = $this->categories->resolveMany($hintStrings, $this->useAi);
        }
    }

    /** Best category for a row: matched hint → single-hint deterministic → default. */
    private function categoryIdFor(?string ...$hints): int
    {
        foreach ($hints as $hint) {
            $key = \Illuminate\Support\Str::lower(trim((string) $hint));
            if ($key !== '' && isset($this->categoryMap[$key])) {
                return $this->categoryMap[$key];
            }
        }

        if ($id = $this->categories->resolve(...array_filter($hints, 'strlen'))) {
            return $id;
        }

        $this->uncategorised++;

        return $this->defaultCategoryId;
    }

    private function detectFormat(array $headers): string
    {
        $h = array_flip($headers);
        $has = fn (...$keys) => (bool) array_intersect($keys, $headers);

        // Shopify / Shopify-style variant export: grouped by Handle, one row per variant.
        if (isset($h['handle'])
            && $has('title', 'product title', 'name')
            && $has('variant sku', 'variant price', 'price', 'variant id', 'option 1', 'option1 value')) {
            return 'shopify';
        }

        if (isset($h['title']) && (isset($h['category_id']) || isset($h['price']))) {
            return 'native';
        }

        return 'generic';
    }

    /* ----------------------------- Shopify ----------------------------- */

    /** @return ImportResult */
    private function importShopify(array $records): array
    {
        $created = 0;
        $skipped = 0;
        $errors = [];

        // Shopify emits one row per variant/image; product fields sit on the first row of
        // each Handle and later variant/image rows leave Handle blank — carry it forward.
        $groups = [];
        $currentHandle = null;
        foreach ($records as $row) {
            $handle = trim((string) $this->get($row, 'Handle'));
            if ($handle !== '') {
                $currentHandle = $handle;
            }
            if ($currentHandle === null) {
                continue;
            }
            $groups[$currentHandle][] = $row;
        }

        // Column name aliases — Shopify's own export and the common variant-export dialects.
        $NAME = ['Title', 'Product Title', 'Name'];
        $BODY = ['Body (HTML)', 'Body HTML', 'Product Description', 'Description'];
        $VENDOR = ['Vendor', 'Brand', 'Manufacturer', 'Make'];
        $CAT = ['Product Category', 'Product Type', 'Type', 'Category', 'Category Name'];
        $CMP = ['Variant Compare At Price', 'Compare At Price', 'Compare Price', 'RRP', 'MSRP'];
        $PRICE = ['Variant Price', 'Price', 'Unit Price', 'Retail Price'];
        $SKU = ['Variant SKU', 'SKU', 'Sku'];
        $QTY = ['Variant Inventory Qty', 'Inventory Quantity', 'Inventory Qty', 'Qty', 'Quantity', 'Stock'];

        // Auto-detect every category up front (one AI call for the leftovers).
        $hints = [];
        foreach ($groups as $rows) {
            $hints[] = (string) $this->get($rows[0], ...$CAT);
        }
        $this->primeCategories($hints);

        foreach ($groups as $handle => $rows) {
            try {
                $base = $rows[0];
                $title = trim((string) $this->get($base, ...$NAME));
                if ($title === '') {
                    $skipped++;

                    continue;
                }

                $compareAt = $this->money($this->get($base, ...$CMP));
                $variantPrice = $this->money($this->get($base, ...$PRICE)) ?? 0.0;
                // "Compare at" is the was-price; the plain price is what they pay now.
                [$price, $salePrice] = ($compareAt && $compareAt > $variantPrice)
                    ? [$compareAt, $variantPrice]
                    : [$variantPrice, null];

                $product = Product::create([
                    'seller_id' => $this->sellerId,
                    'title' => Str::limit($title, 190, ''),
                    'slug' => Str::slug(Str::limit($title, 120, '')).'-'.Str::random(6),
                    'category_id' => $this->categoryIdFor($this->get($base, ...$CAT), $title),
                    'brand_id' => $this->resolveBrandId($this->get($base, ...$VENDOR)),
                    'sku' => $this->get($base, ...$SKU) ?: null,
                    'description' => $this->cleanHtml($this->get($base, ...$BODY)),
                    'condition' => 'new',
                    'price' => $price > 0 ? $price : 0.01,
                    'sale_price' => $salePrice,
                    'quantity' => $this->qty($this->get($base, ...$QTY)),
                    'weight_lbs' => $this->weightToLbs(
                        $this->get($base, 'Variant Grams', 'Grams', 'Weight'),
                        $this->get($base, 'Weight Unit'),
                    ),
                    'status' => 'draft',
                ]);

                $variantPhotos = $this->attachVariants($product, $rows);
                $this->fetchImages($product, $this->shopifyImageUrls($rows), $variantPhotos);

                $created++;
            } catch (\Throwable $e) {
                $errors[] = "\"{$handle}\": ".$this->short($e);
            }
        }

        return ['format' => 'shopify', 'created' => $created, 'skipped' => $skipped, 'errors' => $errors, 'uncategorised' => $this->uncategorised];
    }

    /** @return array<string,string> variant label => the URL of its own photo, for FetchProductImages */
    private function attachVariants(Product $product, array $rows): array
    {
        $photos = [];
        $seen = [];
        $sort = 0;

        foreach ($rows as $row) {
            $optName = trim((string) $this->get($row, 'Option1 Name', 'Option 1 Title', 'Option Name'));
            $optValue = trim((string) $this->get($row, 'Option1 Value', 'Option 1', 'Option Value', 'Variant Title'));

            // "Default Title" = a product with no real options.
            if ($optValue === '' || strcasecmp($optValue, 'Default Title') === 0) {
                continue;
            }

            $optKey = $optName && strcasecmp($optName, 'Title') !== 0 ? $optName : 'Option';
            $label = $optKey !== 'Option' ? "{$optKey}: {$optValue}" : $optValue;
            if (isset($seen[$label])) {
                continue;
            }
            $seen[$label] = true;

            $variantImageUrl = trim((string) $this->get(
                $row,
                'Linked Variant Image Link', 'Linked Variant Image', 'Variant Image',
                'Variant Image Src', 'Variant Image URL',
            ));

            $label = Str::limit($label, 120, '');
            if ($variantImageUrl !== '') {
                $photos[$label] = $variantImageUrl;
            }

            $product->variants()->create([
                'label' => $label,
                'options' => [$optKey => $optValue],
                'sku' => $this->get($row, 'Variant SKU', 'SKU') ?: null,
                'price' => $this->money($this->get($row, 'Variant Price', 'Price')),
                'quantity' => $this->qty($this->get($row, 'Variant Inventory Qty', 'Inventory Quantity', 'Qty', 'Stock')),
                'is_active' => true,
                'sort_order' => $sort++,
            ]);
        }

        return $photos;
    }

    private function weightToLbs(?string $value, ?string $unit): ?float
    {
        $n = (float) preg_replace('/[^0-9.]/', '', (string) $value);
        if ($n <= 0) {
            return null;
        }

        return round($n * match (strtolower(trim((string) $unit))) {
            'kg', 'kgs', 'kilogram', 'kilograms' => 2.20462,
            'g', 'grams', 'gram' => 0.00220462,
            'oz', 'ounce', 'ounces' => 0.0625,
            'lb', 'lbs', 'pound', 'pounds' => 1.0,
            default => 0.00220462,   // no unit → Shopify's default is grams
        }, 2) ?: null;
    }

    /**
     * Photos download after the page has answered, or right away inside a background import.
     * They used to share a time limit with the whole import, so a slow one left later products
     * with no photos and no warning.
     *
     * @param  array<int,string>  $urls
     * @param  array<string,string>  $variantPhotos
     */
    private function fetchImages(Product $product, array $urls, array $variantPhotos = []): void
    {
        if (! $this->withImages || $urls === []) {
            return;
        }

        app()->runningInConsole()
            ? FetchProductImages::dispatchSync($product->id, $urls, $variantPhotos)
            : FetchProductImages::dispatchAfterResponse($product->id, $urls, $variantPhotos);
    }

    /**
     * Every distinct photo for this product — not just the one "primary" image column, but also
     * each variant's own image, which real exports carry in a separate column (Shopify: "Variant
     * Image"; other export tools: "Linked Variant Image Link", etc.).
     *
     * @return array<int,string>
     */
    private function shopifyImageUrls(array $rows): array
    {
        $urls = [];
        foreach ($rows as $row) {
            foreach ([
                $this->get($row, 'Image Src', 'Image Link', 'Image', 'Image URL', 'Image Src URL'),
                $this->get($row, 'Linked Variant Image Link', 'Linked Variant Image', 'Variant Image', 'Variant Image Src', 'Variant Image URL'),
            ] as $src) {
                $src = trim((string) $src);
                if ($src !== '' && filter_var($src, FILTER_VALIDATE_URL)) {
                    $urls[$src] = true;
                }
            }
        }

        return array_keys($urls);
    }

    /**
     * Generic/native CSVs are one row per product, so multiple photos have to
     * come from either a single delimited column ("Images": "url1, url2, url3")
     * or several numbered columns ("Image 1", "Image 2", ...) — support both,
     * plus the common single-image column names as a fallback.
     *
     * @return array<int,string>
     */
    private function flatImageUrls(array $row): array
    {
        $urls = [];

        $delimited = (string) $this->get($row, 'images', 'image urls', 'image_urls', 'photos', 'photo urls', 'gallery', 'gallery images', 'additional images', 'additional_images');
        foreach (preg_split('/[|,;\n]+/u', $delimited) ?: [] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_URL)) {
                $urls[$candidate] = true;
            }
        }

        for ($i = 1; $i <= 10; $i++) {
            $src = trim((string) $this->get($row, "image {$i}", "image{$i}", "photo {$i}", "photo{$i}"));
            if ($src !== '' && filter_var($src, FILTER_VALIDATE_URL)) {
                $urls[$src] = true;
            }
        }

        $single = trim((string) $this->get($row, 'image', 'image url', 'image link', 'image src', 'photo', 'photo url', 'picture', 'main image'));
        if ($single !== '' && filter_var($single, FILTER_VALIDATE_URL)) {
            $urls[$single] = true;
        }

        return array_keys($urls);
    }

    /* -------------------------- Native / generic -------------------------- */

    /** @return ImportResult */
    private function importFlat(array $records, string $format): array
    {
        $created = 0;
        $skipped = 0;
        $errors = [];

        $hints = [];
        foreach ($records as $row) {
            $hints[] = (string) $this->get($row, 'category', 'category name', 'category_name', 'product category', 'product_type', 'department');
            $hints[] = (string) $this->get($row, 'type', 'part type', 'part_type', 'sub category', 'subcategory');
        }
        $this->primeCategories($hints);

        foreach ($records as $i => $row) {
            $line = $i + 2; // header + 1-index

            try {
                $title = trim((string) $this->get(
                    $row,
                    'title', 'name', 'product name', 'product_name', 'product title', 'product_title',
                    'product', 'item name', 'item_name', 'item', 'item title', 'listing title',
                    'description title', 'part name', 'part_name', 'productname', 'prod name',
                ));
                if ($title === '') {
                    $skipped++;

                    continue;
                }

                $rawCat = $this->get($row, 'category_id');
                $categoryId = is_numeric($rawCat) && Category::whereKey((int) $rawCat)->exists()
                    ? (int) $rawCat
                    : $this->categoryIdFor(
                        $this->get($row, 'category', 'category name', 'category_name', 'product category', 'product_type', 'department'),
                        $this->get($row, 'type', 'part type', 'part_type', 'sub category', 'subcategory'),
                        $title,
                    );

                $product = Product::create([
                    'seller_id' => $this->sellerId,
                    'title' => Str::limit($title, 190, ''),
                    'slug' => Str::slug(Str::limit($title, 120, '')).'-'.Str::random(6),
                    'category_id' => $categoryId,
                    'brand_id' => $this->resolveBrandId($this->get($row, 'brand', 'vendor', 'manufacturer', 'make', 'brand name', 'mfr')),
                    'sku' => $this->get($row, 'sku', 'variant sku', 'sku code', 'stock code', 'model') ?: null,
                    'part_number' => $this->get($row, 'part_number', 'part number', 'part #', 'part no', 'partno', 'mpn') ?: null,
                    'oem_number' => $this->get($row, 'oem_number', 'oem number', 'oem #', 'oem', 'interchange') ?: null,
                    'condition' => strtolower(trim((string) $this->get($row, 'condition'))) ?: 'new',
                    'price' => $this->money($this->get($row, 'price', 'variant price', 'unit price', 'unit_price', 'retail price', 'retail', 'msrp', 'list price', 'sale price', 'cost')) ?: 0.01,
                    'quantity' => (int) $this->get($row, 'quantity', 'variant inventory qty', 'stock', 'qty', 'inventory', 'inventory quantity', 'stock qty', 'on hand', 'available'),
                    'description' => $this->cleanHtml($this->get($row, 'description', 'body', 'body (html)', 'body html', 'details', 'long description', 'short description', 'notes')),
                    'status' => 'draft',
                ]);
                $this->fetchImages($product, $this->flatImageUrls($row));
                $created++;
            } catch (\Throwable $e) {
                $errors[] = "Row {$line}: ".$this->short($e);
            }
        }

        return ['format' => $format, 'created' => $created, 'skipped' => $skipped, 'errors' => $errors, 'uncategorised' => $this->uncategorised];
    }

    /* ------------------------------ helpers ------------------------------ */

    /** Case-insensitive column read across possible header spellings. */
    private function get(array $row, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            foreach ([$key, strtolower($key), ucfirst($key)] as $variant) {
                if (array_key_exists($variant, $row) && $row[$variant] !== null && $row[$variant] !== '') {
                    return (string) $row[$variant];
                }
            }
        }

        // Last resort: normalise every header on this row.
        $norm = [];
        foreach ($row as $k => $v) {
            $norm[strtolower(trim((string) $k))] = $v;
        }
        foreach ($keys as $key) {
            $k = strtolower(trim($key));
            if (isset($norm[$k]) && $norm[$k] !== '') {
                return (string) $norm[$k];
            }
        }

        return null;
    }

    /** Parse a stock figure defensively — CSV column drift can put an ID here. */
    private function qty(?string $v): int
    {
        $n = (int) preg_replace('/[^0-9\-]/', '', (string) $v);

        return max(0, min($n, 1_000_000));
    }

    private function money(?string $v): ?float
    {
        if ($v === null || trim($v) === '') {
            return null;
        }
        $n = (float) preg_replace('/[^0-9.\-]/', '', $v);

        return $n > 0 ? round($n, 2) : null;
    }

    /** Keeps real structure (paragraphs, bold, lists) instead of flattening to a wall of text. */
    private function cleanHtml(?string $v): ?string
    {
        // Plain-text feeds sometimes use emoji as pseudo-section markers
        // (e.g. "🔥 PRODUCT FEATURES ✅ item ✅ item"), or carry no structural
        // signal at all for long stretches. Prefer AI restructuring when the
        // seller has it enabled and configured — it can section prose that has
        // no delimiter for a regex to find. The deterministic formatter is the
        // always-available fallback (no key needed, instant, free).
        $structured = ($this->useAi ? $this->aiStructurer->structure((string) $v) : null)
            ?? $this->formatter->format($v);

        return $this->sanitizer->sanitize($structured ?? $v, 8000);
    }

    private function resolveBrandId(?string $name): ?int
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        return Brand::firstOrCreate(
            ['slug' => Str::slug($name)],
            ['name' => $name],
        )->id;
    }

    private function short(\Throwable $e): string
    {
        return Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()), 140, '…');
    }
}
