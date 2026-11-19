<?php

namespace App\Services\Seller;

use App\Jobs\FetchProductImages;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\Seller\Import\ColumnMap;
use App\Services\Seller\Import\CsvTable;
use App\Services\Seller\Import\FitmentMatcher;
use Illuminate\Support\Str;

/**
 * Imports an auto-parts product file from any platform — Shopify, WooCommerce, Amazon, eBay,
 * BigCommerce, Magento, Etsy, Wix, Walmart, the Panmotive template or a home-made spreadsheet.
 * Columns are recognised by name (see ColumnMap), variants are grouped (Shopify handles,
 * WooCommerce variable products, Amazon parent/child, eBay variations), categories are
 * auto-detected (category path → title → AI), Year/Make/Model fitment is matched to our vehicle
 * catalog, and photos are fetched and stored as WebP. Every product lands as a draft.
 *
 * @phpstan-type ImportResult array{format:string, created:int, skipped:int, errors:list<string>, uncategorised:int, variants:int, fitted:int, headers:list<string>}
 */
class ProductCsvImporter
{
    /** Platform labels shown to the seller. */
    public const FORMATS = [
        'shopify' => 'Shopify', 'woocommerce' => 'WooCommerce (WordPress)', 'amazon' => 'Amazon', 'ebay' => 'eBay',
        'bigcommerce' => 'BigCommerce', 'magento' => 'Magento', 'etsy' => 'Etsy', 'wix' => 'Wix', 'walmart' => 'Walmart',
        'native' => 'Panmotive template', 'generic' => 'Spreadsheet',
    ];

    /** WooCommerce/eBay/Wix "type" values that describe the row, not the category. */
    private const KIND_VALUES = ['simple', 'variable', 'variation', 'grouped', 'external', 'variant', 'product', 'choice', 'parent', 'child'];

    private int $sellerId;
    private int $defaultCategoryId;
    private bool $withImages;
    private bool $useAi;

    /** @var (callable(string $stage, int $done, int $total): void)|null */
    private $progress = null;

    /** @var array<string,int> resolved hint => category id */
    private array $categoryMap = [];
    private int $uncategorised = 0;
    private int $variantCount = 0;
    private int $fitted = 0;
    private int $existing = 0;

    /** Cache key prefix for the upload page's photo counter (set by the bulk upload job). */
    public ?string $photoTracker = null;

    public function __construct(
        private CategoryResolver $categories,
        private \App\Support\Html\DescriptionSanitizer $sanitizer,
        private \App\Support\Html\DescriptionFormatter $formatter,
        private DescriptionAiStructurer $aiStructurer,
        private FitmentMatcher $fitment,
    ) {}

    /**
     * @param  (callable(string $stage, int $done, int $total): void)|null  $progress
     * @return ImportResult
     */
    public function import(int $sellerId, string $absolutePath, ?int $defaultCategoryId = null, bool $withImages = true, bool $useAi = true, ?callable $progress = null): array
    {
        $this->sellerId = $sellerId;
        $this->defaultCategoryId = $defaultCategoryId ?: (int) (Category::where('slug', 'other-parts')->value('id') ?? Category::whereNull('parent_id')->orderBy('id')->value('id'));
        $this->withImages = $withImages;
        $this->useAi = $useAi;
        $this->progress = $progress;
        $this->categoryMap = [];
        $this->uncategorised = $this->variantCount = $this->fitted = $this->existing = 0;

        $this->report('reading', 0, 0);
        $table = CsvTable::fromFile($absolutePath);
        $cols = new ColumnMap($table->headers);
        $format = $this->detectFormat($table->headers, $cols);

        $result = $format === 'shopify' ? $this->importShopify($table, $cols) : $this->importFlat($table, $cols, $format);

        return $result + ['format' => $format, 'headers' => $table->headers];
    }

    private function report(string $stage, int $done, int $total): void
    {
        if ($this->progress) {
            ($this->progress)($stage, $done, $total);
        }
    }

    /* ----------------------------- platform ----------------------------- */

    private function detectFormat(array $headers, ColumnMap $cols): string
    {
        $n = array_flip(array_map([ColumnMap::class, 'normalise'], $headers));
        $raw = strtolower(implode('|', $headers));
        $has = fn (string ...$k) => (bool) array_intersect_key($n, array_flip($k));

        return match (true) {
            isset($n['handle']) && $has('title', 'product title', 'name') && $has('variant sku', 'variant price', 'price', 'variant id', 'option1 value', 'option 1') => 'shopify',
            isset($n['handleid']) && isset($n['fieldtype']) => 'wix',
            $has('regular price') && $has('type', 'parent', 'published', 'in stock', 'tax status') => 'woocommerce',
            $has('item sku', 'seller sku', 'feed product type', 'item name', 'asin1', 'external product id', 'parent child') => 'amazon',
            str_contains($raw, 'action(') || $has('customlabel', 'startprice', 'picurl', 'conditionid', 'custom label sku', 'ebay category 1 name') => 'ebay',
            $has('product image file 1', 'product image url 1', 'current stock level', 'product code sku') => 'bigcommerce',
            $has('attribute set code', 'base image', 'additional images') && isset($n['sku']) => 'magento',
            $has('currency code') && $has('image1', 'image 1') => 'etsy',
            $has('productidtype', 'product id type', 'mainimageurl') => 'walmart',
            isset($n['title']) && ($cols->has('category_id') || isset($n['price'])) => 'native',
            default => 'generic',
        };
    }

    /* ----------------------------- Shopify ----------------------------- */

    private function importShopify(CsvTable $table, ColumnMap $cols): array
    {
        $created = $skipped = 0;
        $errors = [];

        // One row per variant/image; product fields sit on each Handle's first row.
        $groups = [];
        $current = null;
        foreach ($table->rows as $row) {
            $handle = trim((string) $this->get($row, 'Handle'));
            if ($handle !== '') {
                $current = $handle;
            }
            if ($current !== null) {
                $groups[$current][] = $row;
            }
        }

        $NAME = ['Title', 'Product Title', 'Name'];
        $BODY = ['Body (HTML)', 'Body HTML', 'Product Description', 'Description'];
        $VENDOR = ['Vendor', 'Brand', 'Manufacturer'];
        $CAT = ['Product Category', 'Product Type', 'Type', 'Category', 'Category Name'];
        $CMP = ['Variant Compare At Price', 'Compare At Price', 'Compare Price', 'RRP', 'MSRP'];
        $PRICE = ['Variant Price', 'Price', 'Unit Price', 'Retail Price'];
        $SKU = ['Variant SKU', 'SKU', 'Sku'];
        $QTY = ['Variant Inventory Qty', 'Inventory Quantity', 'Inventory Qty', 'Qty', 'Quantity', 'Stock'];

        $this->report('categories', 0, count($groups));
        $hints = [];
        foreach ($groups as $rows) {
            $hints[] = (string) $this->get($rows[0], ...$CAT);
            $hints[] = (string) $this->get($rows[0], 'Tags');
        }
        $this->primeCategories($hints);
        $categoryOf = $this->resolveGroupCategories(array_map(fn ($rows) => [
            'hints' => [$this->get($rows[0], ...$CAT), $this->get($rows[0], 'Tags')],
            'title' => trim((string) $this->get($rows[0], ...$NAME)),
        ], $groups));

        $done = 0;
        $total = count($groups);
        foreach ($groups as $handle => $rows) {
            $this->report('products', $done++, $total);
            try {
                $base = $rows[0];
                $title = trim((string) $this->get($base, ...$NAME));
                if ($title === '') {
                    $skipped++;

                    continue;
                }

                if ($this->skipExisting($this->get($base, ...$SKU) ?: null, $title, $this->shopifyImageUrls($rows))) {
                    continue;
                }

                $compareAt = $this->money($this->get($base, ...$CMP));
                $variantPrice = $this->money($this->get($base, ...$PRICE)) ?? 0.0;
                [$price, $salePrice] = ($compareAt && $compareAt > $variantPrice) ? [$compareAt, $variantPrice] : [$variantPrice, null];

                $product = Product::create([
                    'seller_id' => $this->sellerId,
                    'title' => Str::limit($title, 190, ''),
                    'slug' => Str::slug(Str::limit($title, 120, '')).'-'.Str::random(6),
                    'category_id' => $categoryOf[$handle],
                    'brand_id' => $this->resolveBrandId($this->get($base, ...$VENDOR)),
                    'sku' => $this->get($base, ...$SKU) ?: null,
                    'part_number' => $cols->get($base, 'part_number'),
                    'description' => $this->cleanHtml($this->get($base, ...$BODY)),
                    'condition' => 'new',
                    'price' => $price > 0 ? $price : 0.01,
                    'sale_price' => $salePrice,
                    'quantity' => $this->qty($this->get($base, ...$QTY)),
                    'weight_lbs' => $this->weightToLbs($this->get($base, 'Variant Grams', 'Grams', 'Weight'), $this->get($base, 'Variant Weight Unit', 'Weight Unit')),
                    'status' => 'draft',
                ]);

                $variantPhotos = $this->attachShopifyVariants($product, $rows);
                $this->attachFitment($product, $cols, $rows, $title);
                $this->fetchImages($product, $this->shopifyImageUrls($rows), $variantPhotos);
                $created++;
            } catch (\Throwable $e) {
                $errors[] = "\"{$handle}\": ".$this->short($e);
            }
        }
        $this->report('products', $total, $total);

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors, 'uncategorised' => $this->uncategorised, 'variants' => $this->variantCount, 'fitted' => $this->fitted, 'existing' => $this->existing];
    }

    /** @return array<string,string> variant label => the URL of its own photo */
    private function attachShopifyVariants(Product $product, array $rows): array
    {
        $photos = [];
        $seen = [];
        $sort = 0;
        foreach ($rows as $row) {
            $optName = trim((string) $this->get($row, 'Option1 Name', 'Option 1 Title', 'Option Name'));
            $optValue = trim((string) $this->get($row, 'Option1 Value', 'Option 1', 'Option Value', 'Variant Title'));
            if ($optValue === '' || strcasecmp($optValue, 'Default Title') === 0) {
                continue; // a product with no real options
            }
            $optKey = $optName && strcasecmp($optName, 'Title') !== 0 ? $optName : 'Option';
            $label = Str::limit($optKey !== 'Option' ? "{$optKey}: {$optValue}" : $optValue, 120, '');
            if (isset($seen[$label])) {
                continue;
            }
            $seen[$label] = true;

            $img = trim((string) $this->get($row, 'Linked Variant Image Link', 'Linked Variant Image', 'Variant Image', 'Variant Image Src', 'Variant Image URL'));
            if ($img !== '') {
                $photos[$label] = $img;
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
            $this->variantCount++;
        }

        return $photos;
    }

    /** @return array<int,string> */
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

    /* ------------------------- every other platform ------------------------- */

    private function importFlat(CsvTable $table, ColumnMap $cols, string $format): array
    {
        $created = $skipped = 0;
        $errors = [];

        $groups = $this->groupRows($table, $cols);

        // Categories for every product, decided up front (one AI pass for what's left).
        $this->report('categories', 0, count($groups));
        $hints = [];
        foreach ($groups as $g) {
            array_push($hints, ...$this->categoryHints($g['row'], $cols));
        }
        $this->primeCategories($hints);
        $categoryOf = $this->resolveGroupCategories(array_map(fn ($g) => [
            'hints' => $this->categoryHints($g['row'], $cols),
            'title' => (string) $cols->get($g['row'], 'title'),
            'category_id' => $cols->get($g['row'], 'category_id'),
        ], $groups));

        $total = count($groups);
        foreach ($groups as $gi => $g) {
            $this->report('products', $gi, $total);
            $row = $g['row'];
            try {
                $title = (string) $cols->get($row, 'title');
                if ($title === '') {
                    $skipped++;

                    continue;
                }

                $children = array_column($g['children'], 'row');
                if ($this->skipExisting($cols->get($row, 'sku'), $title, array_merge($cols->imageUrls($row), ...array_map(fn ($c) => $cols->imageUrls($c), $children)))) {
                    continue;
                }
                [$price, $sale] = $this->prices($cols, $row, $children);
                $qty = $cols->has('quantity') && $cols->get($row, 'quantity') !== null
                    ? $this->qty($cols->get($row, 'quantity'))
                    : array_sum(array_map(fn ($c) => $this->qty($cols->get($c, 'quantity')), $children));

                $product = Product::create([
                    'seller_id' => $this->sellerId,
                    'title' => Str::limit($title, 190, ''),
                    'slug' => Str::slug(Str::limit($title, 120, '')).'-'.Str::random(6),
                    'category_id' => $categoryOf[$gi],
                    'brand_id' => $this->resolveBrandId($cols->get($row, 'brand')),
                    'sku' => $cols->get($row, 'sku'),
                    'part_number' => $cols->get($row, 'part_number'),
                    'oem_number' => $cols->get($row, 'oem_number'),
                    'condition' => $this->condition($cols->get($row, 'condition')),
                    'price' => $price,
                    'sale_price' => $sale,
                    'quantity' => $qty,
                    'description' => $this->description($cols, $row),
                    'weight_lbs' => $this->weightToLbs($cols->get($row, 'weight'), $cols->get($row, 'weight_unit') ?? $this->unitFromHeader($cols->header('weight'))),
                    'status' => 'draft',
                ]);

                $photos = $cols->imageUrls($row);
                $variantPhotos = $this->attachFlatVariants($product, $cols, $g, $photos);
                $this->attachFitment($product, $cols, [$row, ...$children, ...$g['fitRows']], $title);
                $this->fetchImages($product, array_values(array_unique($photos)), $variantPhotos);
                $created++;
            } catch (\Throwable $e) {
                $errors[] = "Row {$g['line']}: ".$this->short($e);
            }
        }
        $this->report('products', $total, $total);

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors, 'uncategorised' => $this->uncategorised, 'variants' => $this->variantCount, 'fitted' => $this->fitted, 'existing' => $this->existing];
    }

    /**
     * One entry per product with its variant rows: WooCommerce "variation" rows (Parent column),
     * Amazon "child" rows (parent_sku), eBay "Variation" rows (right after their parent), Wix
     * "Variant" rows and repeated handles.
     *
     * @return list<array{row:array, line:int, children:list<array{row:array, line:int}>}>
     */
    private function groupRows(CsvTable $table, ColumnMap $cols): array
    {
        $groups = [];
        $index = [];
        $last = null;
        $key = fn (?string $v) => $v === null ? null : Str::lower(trim(preg_replace('/^id:/i', '', $v)));

        foreach ($table->rows as $i => $row) {
            $line = $table->headerLine + 1 + $i;
            $title = $cols->get($row, 'title');
            if (ColumnMap::looksLikeHeader($title, 'title') || ColumnMap::looksLikeHeader($cols->get($row, 'sku'), 'sku')) {
                continue; // a second header row (Amazon templates)
            }

            $kind = Str::lower((string) $cols->get($row, 'product_kind'));
            $parentRef = $key($cols->get($row, 'parent'));
            $handle = $key($cols->get($row, 'handle'));
            $isChild = in_array($kind, ['variation', 'variant', 'child'], true)
                || Str::lower((string) $cols->get($row, 'parent_child')) === 'child'
                || ($parentRef !== null && $parentRef !== '' && $parentRef !== '0' && $parentRef !== $key($cols->get($row, 'sku')))
                || ($handle && isset($index['h:'.$handle]) && ! $title);

            if ($isChild) {
                $parent = ($parentRef !== null && $parentRef !== '' ? ($index['k:'.$parentRef] ?? null) : null)
                    ?? ($handle ? ($index['h:'.$handle] ?? null) : null)
                    ?? (in_array($kind, ['variation', 'variant'], true) && ! $parentRef ? $last : null);
                if ($parent !== null) {
                    $groups[$parent]['children'][] = ['row' => $row, 'line' => $line];

                    continue;
                }
                if (! $title) {
                    continue; // a variant whose product isn't in the file and has no name of its own
                }
            }

            // Year/Make/Model sheets repeat a product on one row per vehicle: same SKU and name → one
            // product with more fitment, not a duplicate.
            $sku = $key($cols->get($row, 'sku'));
            if ($sku && isset($index['s:'.$sku]) && ! $isChild
                && Str::lower((string) $title) === Str::lower((string) $cols->get($groups[$index['s:'.$sku]]['row'], 'title'))) {
                $groups[$index['s:'.$sku]]['fitRows'][] = $row;

                continue;
            }

            $groups[] = ['row' => $row, 'line' => $line, 'children' => [], 'fitRows' => []];
            $gi = array_key_last($groups);
            foreach (['id', 'sku', 'part_number'] as $f) {
                if ($k = $key($cols->get($row, $f))) {
                    $index['k:'.$k] ??= $gi;
                }
            }
            if ($handle) {
                $index['h:'.$handle] ??= $gi;
            }
            if ($sku) {
                $index['s:'.$sku] ??= $gi;
            }
            $last = $gi;
        }

        return $groups;
    }

    /** @return list<string> */
    private function categoryHints(array $row, ColumnMap $cols): array
    {
        $sub = $cols->get($row, 'subcategory');
        if ($sub !== null && in_array(Str::lower($sub), self::KIND_VALUES, true)) {
            $sub = null; // WooCommerce "Type" = simple/variable, not a category
        }

        return array_values(array_filter([$cols->get($row, 'category'), $sub, $cols->get($row, 'tags')]));
    }

    /** @return array{0:float, 1:?float} [price, sale price] */
    private function prices(ColumnMap $cols, array $row, array $children): array
    {
        $price = $this->money($cols->get($row, 'price'));
        $sale = $this->money($cols->get($row, 'sale_price'));
        $compare = $this->money($cols->get($row, 'compare_at'));

        if ($price === null && $children) {
            $childPrices = array_filter(array_map(fn ($c) => $this->money($cols->get($c, 'price')) ?? $this->money($cols->get($c, 'sale_price')), $children));
            $price = $childPrices ? min($childPrices) : null;
        }
        if ($price === null && $sale !== null) {
            [$price, $sale] = [$sale, null];
        }
        $price ??= 0.01;

        if ($compare !== null && $compare > $price) {
            return [$compare, $sale !== null && $sale < $price ? $sale : $price]; // "was" price → price, current → sale
        }

        return [$price, $sale !== null && $sale < $price ? $sale : null];
    }

    /** @return array<string,string> variant label => its own photo URL */
    private function attachFlatVariants(Product $product, ColumnMap $cols, array $group, array &$photos): array
    {
        $variantPhotos = [];
        $seen = [];
        $sort = 0;
        $parentTitle = Str::lower((string) $cols->get($group['row'], 'title'));

        foreach ($group['children'] as $child) {
            $row = $child['row'];
            $options = $this->variantOptions($cols, $row);
            if ($options === []) {
                $childTitle = (string) $cols->get($row, 'title');
                $diff = trim(str_ireplace($parentTitle, '', $childTitle), " -–,|/");
                $options = ['Option' => $diff !== '' ? $diff : ($cols->get($row, 'sku') ?? 'Option '.($sort + 1))];
            }
            $label = Str::limit(implode(' / ', array_map(fn ($k, $v) => $k === 'Option' ? $v : "{$k}: {$v}", array_keys($options), $options)), 120, '');
            if (isset($seen[$label])) {
                continue;
            }
            $seen[$label] = true;

            $childPhotos = $cols->imageUrls($row);
            if ($childPhotos) {
                $variantPhotos[$label] = $childPhotos[0];
                array_push($photos, ...$childPhotos);
            }
            $price = $this->money($cols->get($row, 'price'));
            $sale = $this->money($cols->get($row, 'sale_price'));

            $product->variants()->create([
                'label' => $label,
                'options' => $options,
                'sku' => $cols->get($row, 'sku'),
                'price' => $sale !== null && ($price === null || $sale < $price) ? $sale : $price,
                'quantity' => $this->qty($cols->get($row, 'quantity')),
                'is_active' => true,
                'sort_order' => $sort++,
            ]);
            $this->variantCount++;
        }

        return $variantPhotos;
    }

    /** @return array<string,string> option name => value */
    private function variantOptions(ColumnMap $cols, array $row): array
    {
        // eBay: "Color=Red|Size=XL"
        if ($details = $cols->get($row, 'relationship_details')) {
            $out = [];
            foreach (preg_split('/\s*\|\s*/', $details) ?: [] as $pair) {
                [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
                if (trim($k) !== '' && trim($v) !== '' && ! str_contains($v, ';')) {
                    $out[trim($k)] = trim($v);
                }
            }
            if ($out) {
                return $out;
            }
        }
        // WooCommerce: Attribute 1 name / value(s)
        if ($attrs = $cols->attributes($row)) {
            return array_map(fn ($v) => trim(explode(',', $v)[0]), $attrs);
        }
        // Amazon & generic: color / size / style columns, or an option name/value pair
        $out = array_filter(['Color' => $cols->get($row, 'color'), 'Size' => $cols->get($row, 'size'), 'Style' => $cols->get($row, 'style')]);
        if ($out) {
            return $out;
        }
        if ($v = $cols->get($row, 'option_value')) {
            return [($cols->get($row, 'option_name') ?: 'Option') => $v];
        }

        return [];
    }

    private function description(ColumnMap $cols, array $row): ?string
    {
        $body = (string) ($cols->get($row, 'description') ?? $cols->get($row, 'short_description') ?? '');
        $bullets = $cols->bullets($row);
        if ($bullets) {
            $list = '<ul>'.implode('', array_map(fn ($b) => '<li>'.e($b).'</li>', $bullets)).'</ul>';
            $body = $body === '' ? $list : $list.(str_contains($body, '<') ? $body : '<p>'.e($body).'</p>');
        }

        return $body === '' ? null : $this->cleanHtml($body);
    }

    /* ----------------------------- shared ----------------------------- */

    /** Resolve every distinct category text in one go (deterministic + one AI pass). */
    private function primeCategories(array $hintStrings): void
    {
        $hintStrings = array_values(array_unique(array_filter(array_map('trim', $hintStrings))));
        if ($hintStrings) {
            $this->categoryMap = $this->categories->resolveMany($hintStrings, $this->useAi);
        }
    }

    /**
     * Category for each product: a numeric category id (Panmotive template) → its category texts →
     * its title → AI on the titles still unmatched → the fallback category.
     *
     * @param  array<int|string, array{hints:array, title:string, category_id?:?string}>  $items
     * @return array<int|string,int>
     */
    private function resolveGroupCategories(array $items): array
    {
        $out = [];
        $unresolved = [];
        foreach ($items as $k => $item) {
            $raw = $item['category_id'] ?? null;
            if (is_numeric($raw) && Category::whereKey((int) $raw)->exists()) {
                $out[$k] = (int) $raw;

                continue;
            }
            foreach (array_filter($item['hints']) as $hint) {
                $key = Str::lower(trim((string) $hint));
                if (isset($this->categoryMap[$key])) {
                    $out[$k] = $this->categoryMap[$key];

                    continue 2;
                }
            }
            if ($id = $this->categories->resolve(...array_values(array_filter([...$item['hints'], $item['title']], 'strlen')))) {
                $out[$k] = $id;

                continue;
            }
            $unresolved[$k] = $item['title'];
        }

        if ($unresolved && $this->useAi) {
            $byTitle = $this->categories->resolveMany(array_values(array_filter($unresolved)), true);
            foreach ($unresolved as $k => $title) {
                if ($id = $byTitle[Str::lower(trim($title))] ?? null) {
                    $out[$k] = $id;
                    unset($unresolved[$k]);
                }
            }
        }

        foreach ($unresolved as $k => $title) {
            $out[$k] = $this->defaultCategoryId;
            if (trim($title) !== '') {
                $this->uncategorised++;
            }
        }

        return $out;
    }

    /** Year/Make/Model columns or fitment text (any row of the product), else "2018-2022 Honda Accord" in the title. */
    private function attachFitment(Product $product, ColumnMap $cols, array $rows, string $title): void
    {
        $ids = [];
        foreach ($rows as $row) {
            if ($cols->has('make') && $cols->has('model')) {
                array_push($ids, ...$this->fitment->fromColumns($cols->get($row, 'year'), $cols->get($row, 'make'), $cols->get($row, 'model')));
            }
            array_push($ids, ...$this->fitment->fromText($cols->get($row, 'fitment')));
        }
        if ($ids === []) {
            $ids = $this->fitment->fromText($title);
        }
        $ids = array_values(array_unique($ids));
        if ($ids) {
            $product->fitments()->syncWithoutDetaching($ids);
            $this->fitted++;
        }
    }

    /**
     * Photos are fetched as separate jobs, so a slow photo host can't hold up (or time out) the
     * import; inside tests the queue runs them straight away.
     *
     * @param  array<int,string>  $urls
     * @param  array<string,string>  $variantPhotos
     */
    private function fetchImages(Product $product, array $urls, array $variantPhotos = []): void
    {
        if (! $this->withImages || $urls === []) {
            return;
        }
        FetchProductImages::countPlanned($this->photoTracker, FetchProductImages::planned($urls));
        FetchProductImages::dispatch($product->id, $urls, $variantPhotos, $this->photoTracker);
    }

    /**
     * Uploading the same file again must not create duplicates: a product this seller already has
     * (same SKU, or same title when the file has no SKU) is left as it is, except that its photos are
     * downloaded if it has none yet — so re-uploading with photos switched on just adds the photos.
     *
     * @param  array<int,string>  $photoUrls
     */
    private function skipExisting(?string $sku, string $title, array $photoUrls): bool
    {
        $query = Product::where('seller_id', $this->sellerId);
        $product = $sku
            ? (clone $query)->where('sku', $sku)->first()
            : (clone $query)->where('title', Str::limit($title, 190, ''))->first();
        if (! $product) {
            return false;
        }
        $this->existing++;
        if (! $product->hasMedia('images')) {
            $this->fetchImages($product, array_values(array_unique($photoUrls)));
        }

        return true;
    }

    private function condition(?string $value): string
    {
        $v = Str::lower(trim((string) $value));

        return match (true) {
            $v === '' => 'new',
            in_array($v, ['1000', '1500', '1750'], true) => 'new',              // eBay: New / New other / New with defects
            in_array($v, ['2000', '2010', '2020', '2030', '2500'], true) => 'refurbished',
            in_array($v, ['3000', '4000', '5000', '6000', '7000'], true) => 'used',
            str_contains($v, 'refurb') || str_contains($v, 'reman') || str_contains($v, 'rebuilt') => 'refurbished',
            str_contains($v, 'used') || str_contains($v, 'pre-owned') || str_contains($v, 'preowned') || str_contains($v, 'for parts') || str_contains($v, 'salvage') => 'used',
            default => 'new',
        };
    }

    private function unitFromHeader(?string $header): string
    {
        $h = ColumnMap::normalise((string) $header);

        return match (true) {
            str_contains($h, 'gram') || str_ends_with($h, ' g') => 'g',
            str_contains($h, 'kg') => 'kg',
            str_contains($h, 'oz') => 'oz',
            default => 'lb', // US marketplace files are in pounds unless they say otherwise
        };
    }

    private function weightToLbs(?string $value, ?string $unit): ?float
    {
        $n = (float) preg_replace('/[^0-9.]/', '', (string) $value);
        if ($n <= 0) {
            return null;
        }
        if (! $unit && preg_match('/(kg|kgs|g|grams?|oz|ounces?|lbs?|pounds?)\b/i', (string) $value, $m)) {
            $unit = $m[1];
        }

        return round($n * match (strtolower(trim((string) $unit))) {
            'kg', 'kgs', 'kilogram', 'kilograms' => 2.20462,
            'g', 'grams', 'gram' => 0.00220462,
            'oz', 'ounce', 'ounces' => 0.0625,
            'lb', 'lbs', 'pound', 'pounds' => 1.0,
            default => 0.00220462,   // Shopify with no unit: grams
        }, 2) ?: null;
    }

    /** Case-insensitive column read across possible header spellings (Shopify). */
    private function get(array $row, string ...$keys): ?string
    {
        $norm = null;
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return (string) $row[$key];
            }
        }
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

    /** Parse a stock figure defensively — column drift can put an ID here. */
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
        $v = trim($v);
        // "1.234,56" (European) → 1234.56; "1,234.56" → 1234.56
        if (preg_match('/^\D*\d{1,3}(\.\d{3})+,\d{1,2}\D*$/', $v) || preg_match('/^\D*\d+,\d{1,2}\D*$/', $v)) {
            $v = str_replace(['.', ','], ['', '.'], $v);
        }
        $n = (float) preg_replace('/[^0-9.\-]/', '', $v);

        return $n > 0 ? round($n, 2) : null;
    }

    /** Keeps real structure (paragraphs, bold, lists) instead of flattening to a wall of text. */
    private function cleanHtml(?string $v): ?string
    {
        $structured = ($this->useAi ? $this->aiStructurer->structure((string) $v) : null) ?? $this->formatter->format($v);

        return $this->sanitizer->sanitize($structured ?? $v, 8000);
    }

    private function resolveBrandId(?string $name): ?int
    {
        $name = trim((string) $name);
        if ($name === '' || Str::length($name) > 80) {
            return null;
        }

        return Brand::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name])->id;
    }

    private function short(\Throwable $e): string
    {
        return Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()), 140, '…');
    }
}
