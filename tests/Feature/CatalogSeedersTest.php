<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Vehicle;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Seller\CategoryResolver;
use Database\Seeders\BrandSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\VehicleMakeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSeedersTest extends TestCase
{
    use RefreshDatabase;

    public function test_categories_seed_the_full_tree_and_keep_old_slugs_and_products(): void
    {
        // An existing install: the old sub-category with a live product.
        $engine = Category::create(['name' => 'Engine', 'slug' => 'engine']);
        $old = Category::create(['name' => 'Spark Plugs', 'slug' => 'spark-plugs', 'parent_id' => $engine->id]);

        $this->seed(CategorySeeder::class);
        $count = Category::count();
        $this->seed(CategorySeeder::class);

        $this->assertSame($count, Category::count(), 'running twice adds nothing');
        $this->assertSame(30, Category::whereNull('parent_id')->count());
        $this->assertGreaterThan(300, Category::whereNotNull('parent_id')->count());

        // Moved under Ignition, same row (so its products come along).
        $this->assertSame('ignition', $old->fresh()->parent->slug);
        $this->assertSame(999, Category::where('slug', 'other-parts')->value('sort_order'));
        $this->assertSame('Brake Calipers', Category::where('slug', 'calipers')->value('name'));
    }

    public function test_every_importer_keyword_points_at_a_real_category(): void
    {
        $slugs = CategorySeeder::slugs();
        $keywords = (new \ReflectionClassConstant(CategoryResolver::class, 'KEYWORDS'))->getValue();

        $missing = array_filter($keywords, fn ($slug) => ! isset($slugs[$slug]));
        $this->assertSame([], $missing);
    }

    public function test_titles_land_in_the_most_specific_category(): void
    {
        $this->seed(CategorySeeder::class);
        $resolver = app(CategoryResolver::class);
        $slug = fn (string $title) => Category::find($resolver->resolve($title))?->slug;

        $cases = [
            'Front Wheel Bearing and Hub Assembly for 2015 F-150' => 'wheel-bearings-hubs',
            'Ceramic Brake Pads Front Set' => 'brake-pads',
            'Brake Rotors Pair Drilled Slotted' => 'brake-rotors',
            'Engine Oil Filter 2-Pack' => 'oil-filters',
            'Cabin Air Filter with Activated Carbon' => 'cabin-air-filters',
            'Upstream Oxygen Sensor O2' => 'oxygen-sensors',
            'Tire Pressure Sensor TPMS 315MHz' => 'tpms-sensors',
            'ABS Wheel Speed Sensor Rear Left' => 'abs-wheel-speed-sensors',
            'Steering Wheel Cover Leather' => 'steering-wheel-covers',
            'Outer Tie Rod End' => 'tie-rods',
            'Ignition Coil Pack Set of 6' => 'ignition-coils',
            'Iridium Spark Plugs 4pc' => 'spark-plugs',
            'Cat-Back Exhaust System Stainless' => 'cat-back-exhaust-systems',
            'Direct Fit Catalytic Converter' => 'catalytic-converters',
            'AC Compressor with Clutch' => 'ac-compressors',
            'Radiator Hose Upper' => 'radiator-hoses',
            'LED Headlight Bulbs H11 6000K' => 'led-headlight-bulbs',
            'Smoked LED Tail Lights' => 'tail-lights',
            '3 inch Leveling Kit' => 'leveling-kits',
            'CV Axle Shaft Front Passenger' => 'cv-axles',
            'Hard Folding Tonneau Cover' => 'tonneau-covers',
            'All Weather Floor Mats' => 'floor-mats',
            'OBD2 Scanner Code Reader' => 'obd2-scanners-diagnostics',
            'Full Synthetic Motor Oil 5W-30' => 'motor-oil',
            'Wiper Blades 26 + 18' => 'wiper-blades',
            'Trailer Hitch Class III' => 'trailer-hitches',
            'Honda Accord LED Reflectors V3' => 'led-accent-lights',
            'Acura Wide Angle Side Mirrors' => 'mirrors',
        ];
        foreach ($cases as $title => $expected) {
            $this->assertSame($expected, $slug($title), $title);
        }
    }

    public function test_brands_seed_once_and_match_the_importer_slugs(): void
    {
        Brand::create(['name' => 'Bosch', 'slug' => 'bosch', 'logo_path' => 'brands/bosch.png']);

        $this->seed(BrandSeeder::class);
        $count = Brand::count();
        $this->seed(BrandSeeder::class);

        $this->assertSame($count, Brand::count());
        $this->assertGreaterThan(800, $count);
        $this->assertSame('brands/bosch.png', Brand::where('slug', 'bosch')->value('logo_path'), 'existing brand kept');
        $this->assertTrue(Brand::where('slug', 'kn')->where('name', 'K&N')->exists(), 'same slug the CSV importer makes');
    }

    public function test_vehicles_cover_every_us_make_with_real_years(): void
    {
        // The old sample list had "F-250 Super Duty"; the real catalog calls it "F-250".
        $ford = VehicleMake::create(['name' => 'Ford', 'slug' => 'ford']);
        $legacy = VehicleModel::create(['vehicle_make_id' => $ford->id, 'name' => 'F-250 Super Duty', 'slug' => 'f-250-super-duty']);
        Vehicle::create(['vehicle_make_id' => $ford->id, 'vehicle_model_id' => $legacy->id, 'year' => 2015]);

        $this->seed(VehicleMakeSeeder::class);
        $count = Vehicle::count();
        $this->seed(VehicleMakeSeeder::class);
        $this->assertSame($count, Vehicle::count(), 'running twice adds nothing');

        $this->assertSame(56, VehicleMake::count(), "famous US brands only");
        $this->assertFalse(VehicleMake::where("slug", "yugo")->exists());
        $this->assertGreaterThan(12000, $count);
        $this->assertNull($legacy->fresh(), 'unused old sample model removed');

        $years = fn (string $make, string $model) => Vehicle::whereHas('make', fn ($q) => $q->where('name', $make))
            ->whereHas('model', fn ($q) => $q->where('name', $model))->pluck('year')->sort()->values();

        $this->assertTrue($years('Ford', 'F-150')->contains(1985));
        $this->assertTrue($years('Ford', 'F-150')->contains(2024));
        $this->assertTrue($years('Ford', 'F-250')->contains(2015));
        $this->assertTrue($years('Toyota', 'Camry')->contains(1990));
        $this->assertTrue($years('Chevrolet', 'Silverado 2500 HD')->contains(2020));
        $this->assertTrue($years('Scion', 'tC')->contains(2010));
        $this->assertFalse($years('Chevrolet', 'Beretta')->contains(1985), 'Beretta started in 1987');
        $this->assertTrue($years('Chevrolet', 'Beretta')->contains(1990));
        $this->assertTrue($years('Honda', 'Ridgeline')->contains(2014));
        $this->assertFalse($years('Honda', 'Ridgeline')->contains(2015), 'no 2015 Ridgeline');
        $this->assertTrue($years('Honda', 'Ridgeline')->contains(2017));
    }
}
