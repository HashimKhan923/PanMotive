<?php

namespace App\Services\Seller\Import;

/**
 * Knows what every platform calls each product column (Shopify, WooCommerce, Amazon, eBay,
 * BigCommerce, Magento, Etsy, Wix, Walmart and home-made spreadsheets) and maps a file's
 * headers onto our own fields. Headers are compared after normalising: lower case, no
 * punctuation, no eBay "*" / "C:" prefixes and no "(SiteID=…)" style notes.
 */
class ColumnMap
{
    /** field => header spellings, most specific first (all already normalised) */
    public const FIELDS = [
        'title' => ['title', 'product title', 'product name', 'item name', 'item title', 'listing title', 'name', 'productname',
            'part name', 'post title', 'item', 'product', 'prod name', 'description title'],
        'description' => ['body html', 'description html', 'product description', 'long description', 'full description',
            'item description', 'html description', 'description', 'body', 'details', 'post content', 'desc', 'product details'],
        'short_description' => ['short description', 'post excerpt', 'summary', 'subtitle', 'short desc'],
        'price' => ['variant price', 'regular price', 'standard price', 'your price', 'start price', 'startprice', 'buy it now price',
            'buyitnowprice', 'current price', 'unit price', 'selling price', 'price', 'item price', 'price usd', 'base price'],
        'sale_price' => ['sale price', 'special price', 'discounted price', 'offer price', 'promo price'],
        'compare_at' => ['variant compare at price', 'compare at price', 'compare price', 'compare at', 'msrp', 'rrp', 'retail price',
            'list price', 'was price', 'original price'],
        'quantity' => ['variant inventory qty', 'inventory quantity', 'inventory qty', 'available quantity', 'quantity available',
            'current stock level', 'stock quantity', 'stock qty', 'stock level', 'quantity', 'qty', 'stock', 'inventory', 'on hand', 'available'],
        'sku' => ['variant sku', 'item sku', 'seller sku', 'custom label sku', 'custom label', 'customlabel', 'product code sku',
            'product code', 'item code', 'stock code', 'sku code', 'sku'],
        'part_number' => ['manufacturer part number', 'mfr part number', 'mfg part number', 'manufacturer part no', 'part number',
            'part no', 'partno', 'part num', 'mpn', 'item model number', 'model number'],
        'oem_number' => ['oe oem part number', 'oem part number', 'oe part number', 'oem number', 'oe number', 'interchange part number',
            'interchange number', 'interchange', 'oem', 'oe', 'superseded part number', 'cross reference', 'cross reference number'],
        'brand' => ['brand', 'brand name', 'vendor', 'manufacturer', 'manufacturer name', 'mfr', 'mfg'],
        'condition' => ['condition', 'condition id', 'conditionid', 'item condition', 'condition type', 'condition note'],
        'category' => ['product category', 'categories', 'category', 'category name', 'category path', 'ebay category 1 name',
            'ebay category name', 'category 1', 'store category', 'item type', 'feed product type', 'item type keyword', 'department',
            'product type', 'google product category', 'collection', 'collections'],
        'subcategory' => ['part type', 'sub category', 'subcategory', 'sub type', 'type'],
        'tags' => ['tags', 'keywords', 'search terms', 'generic keywords', 'generic keyword', 'tag'],
        'weight' => ['variant grams', 'grams', 'weight', 'item weight', 'shipping weight', 'package weight', 'weight lbs', 'weight lb',
            'weight oz', 'weight kg', 'weight g', 'product weight'],
        'weight_unit' => ['weight unit', 'variant weight unit', 'item weight unit of measure', 'shipping weight unit of measure',
            'website shipping weight unit of measure', 'weight unit of measure'],
        'image' => ['image src', 'main image url', 'main image', 'featured image', 'image url', 'image link', 'image', 'picture url',
            'picture', 'photo', 'photo url', 'product image url', 'base image', 'productimageurl', 'imageurl', 'thumbnail', 'picurl',
            'pic url', 'item photo url', 'mainimageurl', 'image 1 url'],
        'images' => ['images', 'image urls', 'gallery', 'gallery images', 'additional images', 'additional image urls', 'product images',
            'photos', 'photo urls', 'picture urls', 'other images', 'additional image link', 'image links'],
        'variant_image' => ['variant image', 'variant image src', 'variant image url', 'linked variant image link', 'linked variant image'],
        // grouping (variants)
        'handle' => ['handle', 'handleid', 'url key', 'parent handle'],
        'id' => ['id', 'product id', 'item number', 'item id', 'listing id'],
        'parent' => ['parent', 'parent sku', 'parent id', 'parent item', 'parent asin'],
        'parent_child' => ['parent child', 'parentage', 'parentage level'],
        'product_kind' => ['type', 'fieldtype', 'field type', 'relationship'],
        'relationship_details' => ['relationship details', 'relationshipdetails', 'variation details'],
        'option_name' => ['option1 name', 'option 1 name', 'option name', 'variation 1 type', 'variation theme', 'variation type'],
        'option_value' => ['option1 value', 'option 1 value', 'option value', 'variation 1 value', 'variant title', 'variation', 'variant'],
        'color' => ['color', 'colour', 'color name', 'colour name', 'finish'],
        'size' => ['size', 'size name'],
        'style' => ['style', 'style name'],
        // fitment
        'year' => ['year', 'years', 'model year', 'vehicle year', 'year range'],
        'make' => ['make', 'vehicle make', 'car make', 'make name'],
        'model' => ['model', 'vehicle model', 'car model', 'model name'],
        'fitment' => ['fitment', 'fitments', 'compatibility', 'compatible vehicles', 'vehicle fitment', 'fits', 'fits vehicles',
            'vehicle compatibility', 'application', 'applications', 'vehicle', 'vehicles'],
        // Panmotive template only
        'category_id' => ['category id'],
    ];

    /** @var array<string,string> field => the file's original header */
    private array $map = [];

    /** @var list<string> */
    private array $headers;

    /** @param list<string> $headers */
    public function __construct(array $headers)
    {
        $this->headers = $headers;
        $byNorm = [];
        foreach ($headers as $h) {
            $byNorm[self::normalise($h)] ??= $h;
        }
        $taken = [];
        foreach (self::FIELDS as $field => $names) {
            foreach ($names as $name) {
                if (isset($byNorm[$name]) && ! isset($taken[$byNorm[$name]])) {
                    $this->map[$field] = $byNorm[$name];
                    // A header serves one field, except "type"/"model" style overlaps handled below.
                    if (! in_array($field, ['product_kind', 'subcategory'], true)) {
                        $taken[$byNorm[$name]] = true;
                    }
                    break;
                }
            }
        }
    }

    public static function normalise(string $header): string
    {
        $h = trim($header);
        $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
        $h = ltrim($h, '*');
        $h = preg_replace('/^c:\s*/i', '', $h);              // eBay item specifics: "C:Brand"
        $h = preg_replace('/\([^)]*\)/', ' ', $h);          // "(SiteID=US|...)", "(HTML)"
        $h = strtolower((string) $h);
        $h = preg_replace('/[^a-z0-9]+/', ' ', $h);

        return trim(preg_replace('/\s+/', ' ', (string) $h));
    }

    /** How many headers in this row are recognised product columns (used to find the header row). */
    public static function score(array $cells): int
    {
        static $known = null;
        $known ??= array_flip(array_merge(...array_values(self::FIELDS)));
        $n = 0;
        foreach ($cells as $c) {
            $norm = self::normalise((string) $c);
            if ($norm !== '' && (isset($known[$norm]) || self::isNumberedImage($norm) || self::isBullet($norm))) {
                $n++;
            }
        }

        return $n;
    }

    public function has(string $field): bool
    {
        return isset($this->map[$field]);
    }

    public function header(string $field): ?string
    {
        return $this->map[$field] ?? null;
    }

    /** A row's value for a field, trimmed; null when empty or the column doesn't exist. */
    public function get(array $row, string $field): ?string
    {
        $h = $this->map[$field] ?? null;
        if ($h === null) {
            return null;
        }
        $v = trim((string) ($row[$h] ?? ''));

        return $v === '' ? null : $v;
    }

    /** True when a value looks like one of the known header names for this field (e.g. Amazon's second header row). */
    public static function looksLikeHeader(?string $value, string $field): bool
    {
        return $value !== null && in_array(self::normalise($value), self::FIELDS[$field] ?? [], true);
    }

    /**
     * Every photo URL in the row: the main image column, delimited gallery columns and numbered
     * columns like "Image 2", "other_image_url3", "PicURL", "Product Image File - 1", "IMAGE4".
     *
     * @return list<string>
     */
    public function imageUrls(array $row): array
    {
        $urls = [];
        $add = function (?string $value) use (&$urls) {
            foreach (preg_split('/\s*[|,;\n]\s*|\s+(?=https?:\/\/)/u', (string) $value) ?: [] as $candidate) {
                $candidate = trim($candidate, " \t\"'");
                if ($candidate !== '' && preg_match('#^https?://#i', $candidate) && filter_var($candidate, FILTER_VALIDATE_URL)) {
                    $urls[$candidate] = true;
                }
            }
        };

        $add($this->get($row, 'image'));
        $add($this->get($row, 'images'));
        $numbered = [];
        foreach ($this->headers as $h) {
            $norm = self::normalise($h);
            if (self::isNumberedImage($norm) && $h !== ($this->map['image'] ?? null)) {
                preg_match('/(\d+)/', $norm, $m);
                $numbered[(int) ($m[1] ?? 0)][] = $h;
            }
        }
        ksort($numbered);
        foreach ($numbered as $hs) {
            foreach ($hs as $h) {
                $add((string) ($row[$h] ?? ''));
            }
        }

        return array_keys($urls);
    }

    /** Amazon / generic feature bullets ("bullet_point1", "Key Product Features", "Feature 3"). */
    public function bullets(array $row): array
    {
        $out = [];
        foreach ($this->headers as $h) {
            if (self::isBullet(self::normalise($h))) {
                $v = trim((string) ($row[$h] ?? ''));
                if ($v !== '') {
                    $out[] = $v;
                }
            }
        }

        return $out;
    }

    /** WooCommerce "Attribute 1 name" / "Attribute 1 value(s)" pairs. */
    public function attributes(array $row): array
    {
        $out = [];
        foreach ($this->headers as $h) {
            if (preg_match('/^attribute (\d+) name$/', self::normalise($h), $m)) {
                $name = trim((string) ($row[$h] ?? ''));
                $valueHeader = collect($this->headers)->first(fn ($x) => preg_match('/^attribute '.$m[1].' values?$/', self::normalise($x)));
                $value = $valueHeader ? trim((string) ($row[$valueHeader] ?? '')) : '';
                if ($name !== '' && $value !== '') {
                    $out[$name] = $value;
                }
            }
        }

        return $out;
    }

    private static function isNumberedImage(string $norm): bool
    {
        return (bool) preg_match('/^(other |additional |alt |alternate )?(image|photo|picture|pic|img|product image|product image file|product image url)( ?url| ?link| ?src| ?file)? ?\d{1,2}( url| link| src)?$/', $norm)
            || (bool) preg_match('/^image\d{1,2}$/', $norm);
    }

    private static function isBullet(string $norm): bool
    {
        return $norm === 'key product features'
            || (bool) preg_match('/^(bullet point|bullet|key product features?|feature|highlight|key feature) ?\d{1,2}$/', $norm);
    }
}
