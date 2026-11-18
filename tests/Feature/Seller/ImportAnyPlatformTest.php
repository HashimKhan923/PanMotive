<?php

namespace Tests\Feature\Seller;

use App\Jobs\ProcessBulkProductUpload;
use App\Livewire\Seller\BulkUpload;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Seller\ProductCsvImporter;
use Database\Seeders\CategorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Product files exported from other platforms import with the right categories, variants and fitment. */
class ImportAnyPlatformTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(CategorySeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seller = User::factory()->create();
        $this->seller->assignRole('seller');
        SellerProfile::create(['user_id' => $this->seller->id, 'business_name' => 'Parts '.uniqid(), 'business_type' => 'llc', 'status' => 'approved', 'slug' => 'p-'.uniqid()]);

        foreach ([
            ['Ford', 'F-150', 2010, 2020], ['Honda', 'Civic', 2014, 2020], ['Honda', 'Accord', 2014, 2020],
            ['Honda', 'CR-V', 2010, 2020], ['Toyota', 'Camry', 2012, 2020], ['Chevrolet', 'Silverado 1500', 2015, 2023],
        ] as [$make, $model, $from, $to]) {
            $mk = VehicleMake::firstOrCreate(['slug' => \Str::slug($make)], ['name' => $make]);
            $md = VehicleModel::create(['vehicle_make_id' => $mk->id, 'name' => $model, 'slug' => \Str::slug($model)]);
            foreach (range($from, $to) as $y) {
                Vehicle::create(['vehicle_make_id' => $mk->id, 'vehicle_model_id' => $md->id, 'year' => $y]);
            }
        }
    }

    private function import(string $fixture): array
    {
        return app(ProductCsvImporter::class)->import($this->seller->id, base_path("tests/Fixtures/{$fixture}"), null, withImages: false, useAi: false);
    }

    private function product(string $title): Product
    {
        return Product::where('seller_id', $this->seller->id)->where('title', $title)->firstOrFail();
    }

    private function years(Product $p): array
    {
        return $p->fitments()->with('model')->get()->map(fn ($v) => $v->model->name.' '.$v->year)->sort()->values()->all();
    }

    public function test_woocommerce_variable_products_become_one_product_with_variants(): void
    {
        $r = $this->import('woocommerce_products.csv');

        $this->assertSame('woocommerce', $r['format']);
        $this->assertSame(2, $r['created'], implode(' | ', $r['errors']));

        $lights = $this->product('Smoked LED Tail Lights for 2015-2017 Ford F-150');
        $this->assertSame('tail-lights', $lights->category->slug);
        $this->assertEqualsCanonicalizing(['Color: Smoked', 'Color: Clear'], $lights->variants()->pluck('label')->all());
        $this->assertEquals(269.99, (float) $lights->price);   // lowest variant price
        $this->assertSame(10, (int) $lights->quantity);          // 4 + 6
        $this->assertSame(['F-150 2015', 'F-150 2016', 'F-150 2017'], $this->years($lights));

        $pads = $this->product('Ceramic Front Brake Pads');
        $this->assertSame('brake-pads', $pads->category->slug);
        $this->assertEquals(49.99, (float) $pads->price);
        $this->assertEquals(39.99, (float) $pads->sale_price);
        $this->assertSame(25, (int) $pads->quantity);
    }

    public function test_amazon_flat_file_with_template_rows_and_tabs(): void
    {
        $r = $this->import('amazon_flat_file.txt');

        $this->assertSame('amazon', $r['format']);
        $this->assertSame(2, $r['created'], implode(' | ', $r['errors']));

        $pads = $this->product('Bosch QuietCast Premium Disc Brake Pad Set');
        $this->assertSame('brake-pads', $pads->category->slug);
        $this->assertSame('Bosch', $pads->brand->name);
        $this->assertEqualsCanonicalizing(['Size: Front', 'Size: Rear'], $pads->variants()->pluck('label')->all());
        $this->assertEquals(38.99, (float) $pads->price);
        $this->assertStringContainsString('<li>Copper-free</li>', (string) $pads->description);

        $plugs = $this->product('Denso Iridium Power Spark Plug 4 Pack');
        $this->assertSame('spark-plugs', $plugs->category->slug);
        $this->assertSame('IK20', $plugs->part_number);
        $this->assertSame(40, (int) $plugs->quantity);
    }

    public function test_ebay_file_exchange_with_condition_and_variations(): void
    {
        $r = $this->import('ebay_file_exchange.csv');

        $this->assertSame('ebay', $r['format']);
        $this->assertSame(2, $r['created'], implode(' | ', $r['errors']));

        $hub = $this->product('Front Wheel Bearing Hub Assembly for 2012-2016 Honda CR-V');
        $this->assertSame('used', $hub->condition);                    // ConditionID 3000
        $this->assertSame('wheel-bearings-hubs', $hub->category->slug);
        $this->assertSame('Timken', $hub->brand->name);
        $this->assertSame('HA590467', $hub->part_number);
        $this->assertCount(5, $this->years($hub));

        $mirror = $this->product('Power Heated Side Mirror');
        $this->assertSame('new', $mirror->condition);
        $this->assertSame('mirrors', $mirror->category->slug);
        $this->assertEqualsCanonicalizing(['Side: Left', 'Side: Right'], $mirror->variants()->pluck('label')->all());
        $this->assertEquals(55.00, (float) $mirror->price);
        $this->assertSame(5, (int) $mirror->quantity);
    }

    public function test_old_excel_file_with_semicolons_and_windows_characters(): void
    {
        $r = $this->import('legacy_excel_1252.csv');

        $this->assertSame(2, $r['created'], implode(' | ', $r['errors']));

        $mirror = $this->product('Retro Café Side Mirror – Chrome');   // accents and dash survive
        $this->assertEquals(24.99, (float) $mirror->price);              // "24,99"
        $this->assertSame('used', $mirror->condition);
        $this->assertSame('mirrors', $mirror->category->slug);

        $alt = $this->product('Remanufactured Alternator 160A');
        $this->assertSame('refurbished', $alt->condition);
        $this->assertSame('alternators', $alt->category->slug);
    }

    public function test_year_make_model_columns_and_titles_become_fitment(): void
    {
        $r = $this->import('ymm_fitment.csv');

        $this->assertSame(3, $r['created'], 'one row per vehicle is still one product: '.implode(' | ', $r['errors']));

        $filter = $this->product('Engine Oil Filter');
        $this->assertSame('oil-filters', $filter->category->slug);
        $this->assertSame(['Accord 2017', 'Civic 2016', 'Civic 2017', 'Civic 2018'], $this->years($filter));

        $this->assertSame(['Camry 2015', 'Camry 2016', 'Camry 2017'], $this->years($this->product('Headlight Assembly Left for 2015-2017 Toyota Camry')));
        $this->assertSame('headlights', $this->product('Headlight Assembly Left for 2015-2017 Toyota Camry')->category->slug);

        // "Chevy" and "Silverado1500" written without the space still match.
        $grille = $this->product('Chrome Grille for 2019-2021 Chevy Silverado1500');
        $this->assertSame(['Silverado 1500 2019', 'Silverado 1500 2020', 'Silverado 1500 2021'], $this->years($grille));
        $this->assertSame('bumpers-grilles', $grille->category->slug);
    }

    public function test_upload_page_runs_the_import_in_the_background_and_shows_progress(): void
    {
        $page = Livewire::actingAs($this->withTwoFactor($this->seller))->test(BulkUpload::class)
            ->set('withImages', false)
            ->set('csvFile', UploadedFile::fake()->createWithContent('amazon.txt', file_get_contents(base_path('tests/Fixtures/amazon_flat_file.txt'))))
            ->call('runImport')
            ->assertHasNoErrors()
            ->assertSet('progress.status', 'done')
            ->assertSet('result.format', 'amazon')
            ->assertSet('result.created', 2)
            ->assertSee('Amazon');

        // The stored upload is cleaned up and the progress record is kept for the page.
        $id = $page->get('importId');
        $this->assertSame('done', Cache::get(ProcessBulkProductUpload::progressKey($this->seller->id, $id))['status']);
        $this->assertSame([], \Storage::disk('local')->files('bulk-uploads/'.$this->seller->id));

        $page->call('startOver')->assertSet('result', null)->assertSet('importId', null)->assertSee('Import products');
    }

    public function test_a_running_import_shows_a_progress_bar(): void
    {
        $id = 'abc-123';
        Cache::put(ProcessBulkProductUpload::progressKey($this->seller->id, $id), ['status' => 'running', 'stage' => 'products', 'done' => 50, 'total' => 100]);

        Livewire::actingAs($this->withTwoFactor($this->seller))->withQueryParams([])->test(BulkUpload::class)
            ->set('importId', $id)
            ->call('refreshProgress')
            ->assertSee('Creating products — 50 of 100')
            ->assertSee('57%')        // 15% + 85% × half
            ->assertSeeHtml('wire:poll.1s="refreshProgress"');
    }
}
