<?php

namespace Tests\Feature\Search;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Suggestions while typing in the search box. */
class SearchSuggestTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $title, array $attrs = []): Product
    {
        $seller = User::factory()->create();
        SellerProfile::create(['user_id' => $seller->id, 'business_name' => 'S '.uniqid(), 'business_type' => 'llc', 'status' => 'approved', 'slug' => 's-'.uniqid()]);

        return Product::create(array_merge([
            'seller_id' => $seller->id, 'category_id' => Category::firstOrCreate(['slug' => 'brake-pads'], ['name' => 'Brake Pads', 'is_active' => true])->id,
            'title' => $title, 'slug' => Str::slug($title).'-'.Str::random(4), 'price' => 40, 'quantity' => 3, 'status' => 'active', 'part_number' => 'BP-'.random_int(1000, 9999),
        ], $attrs));
    }

    public function test_it_suggests_parts_categories_and_brands(): void
    {
        Brand::create(['name' => 'Brembo', 'slug' => 'brembo']);
        $pads = $this->product('Ceramic Brake Pads');
        $this->product('Oil Filter', ['category_id' => Category::create(['name' => 'Filters', 'slug' => 'filters', 'is_active' => true])->id]);
        $this->product('Hidden Brake Kit', ['status' => 'draft']);

        $json = $this->getJson(route('search.suggest', ['q' => 'brake']))->assertOk()->json();

        $this->assertSame(['Ceramic Brake Pads'], array_column($json['products'], 'title'));
        $this->assertSame(route('products.show', $pads->slug), $json['products'][0]['url']);
        $this->assertSame('$40.00', $json['products'][0]['price']);
        $this->assertSame(['Brake Pads'], array_column($json['categories'], 'name'));
        $this->assertSame(route('products.index', ['q' => 'brake']), $json['all']);

        $this->assertSame(['Brembo'], array_column($this->getJson(route('search.suggest', ['q' => 'brem']))->json('brands'), 'name'));
    }

    public function test_too_short_queries_return_nothing_and_fit_is_checked_for_the_garage(): void
    {
        $this->assertSame([], $this->getJson(route('search.suggest', ['q' => 'b']))->json('products'));

        $make = VehicleMake::create(['name' => 'Toyota', 'slug' => 'toyota']);
        $model = VehicleModel::create(['vehicle_make_id' => $make->id, 'name' => 'Camry', 'slug' => 'camry']);
        $camry = Vehicle::create(['vehicle_make_id' => $make->id, 'vehicle_model_id' => $model->id, 'year' => 2018]);
        $this->product('Front Brake Pads')->fitments()->attach($camry->id);

        $json = $this->withSession(['garage.vehicle' => ['id' => $camry->id, 'label' => '2018 Toyota Camry']])
            ->getJson(route('search.suggest', ['q' => 'brake pads']))->assertOk()->json();
        $this->assertTrue($json['products'][0]['fits']);
        $this->assertSame('2018 Toyota Camry', $json['vehicle']);
    }

    public function test_the_header_has_the_suggesting_search_box(): void
    {
        $this->get(config('app.url').'/')->assertOk()
            ->assertSee('searchSuggest(', false)->assertSee('search\/suggest', false);
    }
}
