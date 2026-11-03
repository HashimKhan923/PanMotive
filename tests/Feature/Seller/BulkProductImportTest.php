<?php

namespace Tests\Feature\Seller;

use App\Livewire\Seller\BulkUpload;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Seller\ProductCsvImporter;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BulkProductImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seller(): User
    {
        $u = User::factory()->create();
        $u->assignRole('seller');
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'BI '.uniqid(), 'business_type' => 'llc', 'status' => 'approved', 'slug' => 'bi-'.uniqid()]);

        return $this->withTwoFactor($u);
    }

    private function cats(): array
    {
        return [
            'default' => Category::create(['name' => 'Uncategorised', 'slug' => 'uncat-'.uniqid()])->id,
            'brakePads' => Category::create(['name' => 'Brake Pads', 'slug' => 'brake-pads'])->id,
            'filters' => Category::create(['name' => 'Filters', 'slug' => 'filters'])->id,
        ];
    }

    public function test_shopify_export_is_detected_and_mapped(): void
    {
        $seller = $this->seller();
        $c = $this->cats();

        $result = app(ProductCsvImporter::class)->import(
            $seller->id, base_path('tests/Fixtures/shopify_products.csv'), $c['default'], withImages: false, useAi: false
        );

        $this->assertSame('shopify', $result['format']);
        $this->assertSame(2, $result['created'], 'import errors: '.implode(' | ', $result['errors']));
        $this->assertSame(1, $result['skipped']);   // the no-title handle

        $pads = Product::where('seller_id', $seller->id)->where('title', 'Ceramic Brake Pad Set')->firstOrFail();
        $this->assertSame('draft', $pads->status);
        $this->assertSame($c['brakePads'], $pads->category_id);              // matched "Brake Pads" leaf
        $this->assertEquals(59.00, (float) $pads->price);                    // compare-at becomes price
        $this->assertEquals(45.00, (float) $pads->sale_price);              // variant price becomes sale
        $this->assertNotNull($pads->brand_id);                              // "StopTech" brand created
        $this->assertSame('<p>Low-dust <b>ceramic</b> pads.</p>', $pads->description); // structure kept, sanitized
        $this->assertSame(2, $pads->variants()->count());                   // Front + Rear
        $this->assertEqualsCanonicalizing(
            ['Position: Front', 'Position: Rear'],
            $pads->variants()->pluck('label')->all()
        );

        $filter = Product::where('seller_id', $seller->id)->where('title', 'Oil Filter XL')->firstOrFail();
        $this->assertSame($c['filters'], $filter->category_id);
        $this->assertSame(0, $filter->variants()->count());                 // "Default Title" is not a variant
        $this->assertEquals(8.99, (float) $filter->price);
    }

    public function test_real_world_variant_export_imports(): void
    {
        $seller = $this->seller();
        $c = $this->cats();

        // A live "HRS" store export: headers are "Product Title", "Product Description",
        // "Product Type", "Handle", "Price", "Compare At Price", "Inventory Quantity", etc.
        $result = app(ProductCsvImporter::class)->import(
            $seller->id, base_path('tests/Fixtures/hrs_variant_export.csv'), $c['default'], withImages: false, useAi: false
        );

        $this->assertSame('shopify', $result['format'], 'headers: '.implode(', ', $result['headers'] ?? []));
        $this->assertGreaterThan(0, $result['created'], 'import errors: '.implode(' | ', $result['errors']));
        $this->assertSame(0, $result['skipped']);

        $p = Product::where('seller_id', $seller->id)->first();
        $this->assertNotEmpty($p->title);
        $this->assertSame('draft', $p->status);
        $this->assertGreaterThan(0, (float) $p->price);
    }

    public function test_images_are_fetched_without_risking_a_request_timeout(): void
    {
        // 1x1 transparent PNG — small enough for MediaLibrary's conversions to process instantly.
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        Http::fake(['*' => Http::response($pngBytes, 200, ['Content-Type' => 'image/png'])]);

        $seller = $this->seller();
        $c = $this->cats();

        $result = app(ProductCsvImporter::class)->import(
            $seller->id, base_path('tests/Fixtures/shopify_products.csv'), $c['default'], withImages: true, useAi: false
        );

        $this->assertSame(2, $result['created'], implode(' | ', $result['errors']));
        $pads = Product::where('title', 'Ceramic Brake Pad Set')->firstOrFail();
        $this->assertGreaterThan(0, $pads->getMedia('images')->count());
    }

    public function test_a_dead_image_url_does_not_fail_the_import(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $seller = $this->seller();
        $c = $this->cats();

        $result = app(ProductCsvImporter::class)->import(
            $seller->id, base_path('tests/Fixtures/shopify_products.csv'), $c['default'], withImages: true, useAi: false
        );

        $this->assertSame(2, $result['created'], implode(' | ', $result['errors']));
        $this->assertSame(0, Product::where('title', 'Ceramic Brake Pad Set')->firstOrFail()->getMedia('images')->count());
    }

    public function test_every_distinct_product_and_variant_image_is_imported(): void
    {
        // 1x1 transparent PNG — small enough for MediaLibrary's conversions to process instantly.
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        Http::fake(['*' => Http::response($pngBytes, 200, ['Content-Type' => 'image/png'])]);

        $seller = $this->seller();
        $c = $this->cats();

        // The Acura Integra row in this real-world fixture has two colour variants
        // (White/Blue), each with its own "Linked Variant Image Link" — a column
        // the importer used to ignore entirely, so only one shared photo ever landed.
        $result = app(ProductCsvImporter::class)->import(
            $seller->id, base_path('tests/Fixtures/hrs_variant_export.csv'), $c['default'], withImages: true, useAi: false
        );
        $this->assertGreaterThan(0, $result['created'], implode(' | ', $result['errors']));

        $mirrors = Product::where('title', 'HRS 2023-25 Acura Integra 5th Wide Mirrors - The Elite Series')->firstOrFail();
        $this->assertGreaterThanOrEqual(2, $mirrors->getMedia('images')->count(), 'expected both the primary and the distinct variant photos to be attached');

        $variants = $mirrors->variants()->orderBy('label')->get();
        $this->assertCount(2, $variants);
        foreach ($variants as $variant) {
            $this->assertNotNull($variant->media_id, "variant \"{$variant->label}\" should be linked to its own photo");
        }
        // White and Blue point at genuinely different source photos, so they must
        // resolve to different media rows — not both defaulting to the same one.
        $this->assertNotSame($variants[0]->media_id, $variants[1]->media_id);
    }

    public function test_native_template_still_works_and_bad_rows_are_reported(): void
    {
        $seller = $this->seller();
        $c = $this->cats();

        $result = app(ProductCsvImporter::class)->import(
            $seller->id, base_path('tests/Fixtures/native_products.csv'), $c['default'], withImages: false, useAi: false
        );

        $this->assertSame('native', $result['format']);
        $this->assertSame(2, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame('draft', Product::where('title', 'Front Rotor Pair')->firstOrFail()->status);
    }

    public function test_native_csv_imports_a_delimited_multi_image_column(): void
    {
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        Http::fake(['*' => Http::response($pngBytes, 200, ['Content-Type' => 'image/png'])]);

        $seller = $this->seller();
        $c = $this->cats();

        $result = app(ProductCsvImporter::class)->import(
            $seller->id, base_path('tests/Fixtures/native_products_with_images.csv'), $c['default'], withImages: true, useAi: false
        );

        $this->assertSame(2, $result['created'], implode(' | ', $result['errors']));
        $rotors = Product::where('title', 'Front Rotor Pair')->firstOrFail();
        $this->assertSame(3, $rotors->getMedia('images')->count());
        $filter = Product::where('title', 'Cabin Filter')->firstOrFail();
        $this->assertSame(1, $filter->getMedia('images')->count());
    }

    public function test_category_is_auto_detected_from_type_and_title(): void
    {
        $seller = $this->seller();
        $c = $this->cats();
        Category::create(['name' => 'Brake Rotors', 'slug' => 'brake-rotors']);
        Category::create(['name' => 'Spark Plugs', 'slug' => 'spark-plugs']);

        $path = base_path('tests/Fixtures/uncategorised.csv');
        file_put_contents($path, implode("\n", [
            'name,price,quantity,type',
            'NGK Iridium Spark Plug,9.99,200,',           // detected from title
            'Front Brake Rotor 320mm,74.00,15,brake disc', // detected from type keyword
            'Mystery Widget 9000,5.00,3,',                 // nothing matches -> fallback
        ])."\n");

        $result = app(ProductCsvImporter::class)->import($seller->id, $path, $c['default'], withImages: false, useAi: false);
        @unlink($path);

        $this->assertSame(3, $result['created']);
        $this->assertSame(1, $result['uncategorised']);
        $this->assertSame('spark-plugs', Product::where('title', 'NGK Iridium Spark Plug')->firstOrFail()->category->slug);
        $this->assertSame('brake-rotors', Product::where('title', 'Front Brake Rotor 320mm')->firstOrFail()->category->slug);
        $this->assertSame($c['default'], Product::where('title', 'Mystery Widget 9000')->firstOrFail()->category_id);
    }

    public function test_component_imports_inline_and_shows_a_result(): void
    {
        Storage::fake('local');
        $seller = $this->seller();
        $c = $this->cats();

        Livewire::actingAs($seller)->test(BulkUpload::class)
            ->set('defaultCategoryId', $c['default'])
            ->set('withImages', false)
            ->set('csvFile', UploadedFile::fake()->createWithContent('shopify.csv', file_get_contents(base_path('tests/Fixtures/shopify_products.csv'))))
            ->call('runImport')
            ->assertHasNoErrors()
            ->assertSet('result.created', 2)
            ->assertSet('result.format', 'shopify');

        $this->assertSame(2, Product::where('seller_id', $seller->id)->where('status', 'draft')->count());
    }

    public function test_fallback_category_is_optional(): void
    {
        Storage::fake('local');
        $seller = $this->seller();
        $this->cats();

        Livewire::actingAs($seller)->test(BulkUpload::class)
            ->set('withImages', false)
            ->set('csvFile', UploadedFile::fake()->createWithContent('n.csv', file_get_contents(base_path('tests/Fixtures/native_products.csv'))))
            ->call('runImport')
            ->assertHasNoErrors()
            ->assertSet('result.created', 2);
    }
}
