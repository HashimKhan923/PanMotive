<?php

namespace Tests\Feature\Storefront;

use App\Livewire\Storefront\ProductBrowse;
use App\Livewire\Storefront\VehicleSelector;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Seller\CategoryResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** Search, filters and categories show exactly what's really for sale. */
class SearchFiltersTest extends TestCase
{
    use RefreshDatabase;

    private ?User $defaultSeller = null;

    private function seller(string $status = 'approved'): User
    {
        $u = User::factory()->create();
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'Parts Co', 'business_type' => 'llc', 'status' => $status, 'slug' => 'parts-'.uniqid()]);

        return $u;
    }

    private function category(string $name, ?Category $parent = null): Category
    {
        return Category::create(['name' => $name, 'slug' => Str::slug($name), 'parent_id' => $parent?->id]);
    }

    private function product(array $extra = []): Product
    {
        $this->defaultSeller ??= $this->seller();

        return Product::create(array_merge([
            'seller_id' => $this->defaultSeller->id,
            'category_id' => Category::where('slug', 'other-parts')->value('id'),
            'title' => 'Plain Widget '.Str::random(4), 'slug' => 'p-'.Str::random(10),
            'price' => 100, 'quantity' => 5, 'status' => 'active',
        ], $extra))->fresh();
    }

    /** @return array<int, int> ids in display order */
    private function shop(array $params = []): array
    {
        return Livewire::withQueryParams($params)->test(ProductBrowse::class)
            ->instance()->results->getCollection()->pluck('id')->all();
    }

    private function vehicle(string $make, string $model, int $year): Vehicle
    {
        $m = VehicleMake::firstOrCreate(['slug' => Str::slug($make)], ['name' => $make]);
        $mod = VehicleModel::firstOrCreate(['vehicle_make_id' => $m->id, 'slug' => Str::slug($model)], ['name' => $model]);

        return Vehicle::create(['vehicle_make_id' => $m->id, 'vehicle_model_id' => $mod->id, 'year' => $year]);
    }

    public function test_a_parent_category_includes_its_subcategories(): void
    {
        $root = $this->category('Engine X');
        $child = $this->category('Filters X', $root);
        $inRoot = $this->product(['category_id' => $root->id]);
        $inChild = $this->product(['category_id' => $child->id]);
        $elsewhere = $this->product();

        $ids = $this->shop(['category' => 'engine-x']);
        $this->assertContains($inRoot->id, $ids);
        $this->assertContains($inChild->id, $ids);
        $this->assertNotContains($elsewhere->id, $ids);
        $this->assertSame([$inChild->id], $this->shop(['category' => 'filters-x']));
    }

    public function test_unknown_filter_values_show_nothing_rather_than_everything(): void
    {
        $this->product();

        $this->assertSame([], $this->shop(['category' => 'no-such-thing']));
        $this->assertSame([], $this->shop(['brand' => 'no-such-thing']));
        $this->assertSame([], $this->shop(['make' => 'no-such-thing']));
    }

    public function test_every_search_word_must_match_across_title_brand_part_number_and_fitment(): void
    {
        $brand = Brand::create(['name' => 'DK Motion', 'slug' => 'dk-motion']);
        $match = $this->product(['title' => 'Ceramic Brake Pads', 'brand_id' => $brand->id, 'part_number' => 'BP-2517']);
        $match->fitments()->attach($this->vehicle('Honda', 'Accord', 2019)->id);
        $other = $this->product(['title' => 'Ceramic Brake Rotor']);

        $this->assertSame([$match->id], $this->shop(['q' => 'honda brake']));
        $this->assertSame([$match->id], $this->shop(['q' => 'DK MOTION']));
        $this->assertSame([$match->id], $this->shop(['q' => 'BP2517']));
        $this->assertSame([$match->id], $this->shop(['q' => 'bp-2517']));
        $this->assertSame([$match->id], $this->shop(['q' => '2019 accord']));
        $this->assertSame([$other->id], $this->shop(['q' => 'brake rotors']));
        $this->assertSame([], $this->shop(['q' => 'brake toyota']));
    }

    public function test_short_words_match_whole_words_and_wildcards_are_literal(): void
    {
        $sealed = $this->product(['title' => 'Sealed Bearing']);
        $led = $this->product(['title' => 'LED Reflector']);

        $this->assertSame([$led->id], $this->shop(['q' => 'led']));
        $this->assertSame([], $this->shop(['q' => '%']));
        $this->assertSame([], $this->shop(['q' => 'be_ring']));
        $this->assertSame([$sealed->id], $this->shop(['q' => 'bearing']));
    }

    public function test_a_year_inside_a_title_range_matches(): void
    {
        $wing = $this->product(['title' => 'HRS Wing for GR86 2022-26']);
        $this->product(['title' => 'HRS Wing for BRZ 2013-16']);
        Cache::forget('search.title_year_ranges');

        $this->assertSame([$wing->id], $this->shop(['q' => '2024 wing']));
    }

    public function test_price_sort_uses_the_live_price_not_an_ended_sale(): void
    {
        $ended = $this->product(['price' => 100, 'sale_price' => 10, 'deal_ends_at' => now()->subDay()]);
        $live = $this->product(['price' => 100, 'sale_price' => 40]);
        $plain = $this->product(['price' => 60]);

        $this->assertSame([$live->id, $plain->id, $ended->id], $this->shop(['sort' => 'price_asc']));
        $this->assertSame([$ended->id, $plain->id, $live->id], $this->shop(['sort' => 'price_desc']));
    }

    public function test_out_of_stock_listings_come_last(): void
    {
        $inStock = $this->product();
        $inStock->forceFill(['created_at' => now()->subWeek()])->saveQuietly();
        $gone = $this->product(['quantity' => 0]);

        $this->assertSame([$inStock->id, $gone->id], $this->shop());
    }

    public function test_make_links_filter_by_fitment_or_the_make_named_in_the_title(): void
    {
        $vehicle = $this->vehicle('Lexus', 'IS350', 2020);
        $fits = $this->product(['title' => 'Oil Filter']);
        $fits->fitments()->attach($vehicle->id);
        $named = $this->product(['title' => 'Lexus Trunk Spoiler']);
        $other = $this->product(['title' => 'Honda Mirror']);

        $ids = $this->shop(['make' => 'lexus']);
        $this->assertContains($fits->id, $ids);
        $this->assertContains($named->id, $ids);
        $this->assertNotContains($other->id, $ids);

        $this->assertSame([$fits->id], $this->shop(['make' => 'lexus', 'model' => 'is350']));
        $this->assertSame([$fits->id], $this->shop(['vehicleId' => $vehicle->id]));
    }

    public function test_picking_only_a_make_in_the_vehicle_selector_narrows_the_shop(): void
    {
        $make = VehicleMake::create(['name' => 'Acura', 'slug' => 'acura']);

        Livewire::test(VehicleSelector::class)->set('makeId', $make->id)->call('findParts')
            ->assertRedirect(route('products.index', ['make' => 'acura']));
    }

    public function test_filter_counts_are_real_and_empty_options_are_hidden(): void
    {
        $root = $this->category('Cooling Z');
        $child = $this->category('Radiators Z', $root);
        $this->category('Empty Z');
        $brand = Brand::create(['name' => 'Brand Z', 'slug' => 'brand-z']);
        Brand::create(['name' => 'Unused Z', 'slug' => 'unused-z']);
        $this->product(['category_id' => $child->id, 'brand_id' => $brand->id, 'condition' => 'used']);
        $this->product(['category_id' => $child->id, 'condition' => 'new']);

        $page = Livewire::test(ProductBrowse::class);
        $page->assertSee('Cooling Z')->assertDontSee('Empty Z')->assertDontSee('Unused Z')->assertSee('Brand Z (1)');
        $this->assertSame(2, $page->viewData('categories')->firstWhere('slug', 'cooling-z')->listings_count);
        $this->assertEquals(1, $page->viewData('conditionCounts')['used']);

        // A brand narrows the other filters' counts but keeps its own options open.
        $narrowed = Livewire::withQueryParams(['brand' => 'brand-z'])->test(ProductBrowse::class);
        $this->assertSame(1, $narrowed->viewData('categories')->firstWhere('slug', 'cooling-z')->listings_count);

        Livewire::withQueryParams(['category' => 'cooling-z'])->test(ProductBrowse::class)->assertSee('Radiators Z');
        Livewire::withQueryParams(['category' => 'radiators-z'])->test(ProductBrowse::class)
            ->assertSee('Radiators Z')->assertSee('Cooling Z');
    }

    public function test_chips_show_the_filters_and_remove_them_one_by_one(): void
    {
        $brand = Brand::create(['name' => 'Bosch Y', 'slug' => 'bosch-y']);
        $this->product(['brand_id' => $brand->id]);
        $this->product();

        $page = Livewire::withQueryParams(['brand' => 'bosch-y'])->test(ProductBrowse::class)->assertSee('Remove filter Bosch Y');
        $this->assertSame(1, $page->instance()->results->total());

        $page->call('removeFilter', 'brand')->assertSet('brand', '')->assertDontSee('Remove filter Bosch Y');
        $this->assertSame(2, $page->instance()->results->total());
    }

    public function test_listings_of_suspended_sellers_disappear_from_the_storefront(): void
    {
        $hidden = $this->product(['seller_id' => $this->seller('suspended')->id, 'title' => 'Banned Seller Part']);
        $visible = $this->product(['title' => 'Good Seller Part']);

        $this->assertSame([$visible->id], $this->shop());
        $this->get(route('products.show', $hidden->slug))->assertNotFound();
        $this->get(route('products.show', $visible->slug))->assertOk();
        $this->get(route('home'))->assertOk()->assertDontSee('Banned Seller Part');
    }

    public function test_home_and_part_finder_only_link_categories_that_have_parts(): void
    {
        $root = $this->category('Stocked Root');
        $child = $this->category('Stocked Child', $root);
        $this->category('Bare Root');
        $this->product(['category_id' => $child->id]);
        $this->product(['category_id' => $child->id]);
        Cache::forget('shop.category_roots');

        $this->get(route('home'))->assertOk()->assertSee('Stocked Root')->assertSee('2 parts')->assertDontSee('Bare Root');
        $this->get(route('find-parts'))->assertOk()->assertSee('Stocked Root')->assertDontSee('Bare Root');
    }

    public function test_imported_titles_file_correctly_and_unmatched_ones_stay_unresolved(): void
    {
        foreach (['Brake Pads' => 'brake-pads', 'Brakes' => 'brakes'] as $name => $slug) {
            $this->category($name);
        }
        $resolver = app(CategoryResolver::class);
        $slug = fn (string $title) => Category::find($resolver->resolve($title))?->slug;

        $this->assertSame('led-accent-lights', $slug('Honda Accord LED Reflectors V3'));
        $this->assertSame('led-accent-lights', $slug('GR86 BRZ LED License Plate Lights'));
        $this->assertSame('mirrors', $slug('Acura Wide Angle Side Mirrors'));
        $this->assertSame('spoilers-wings', $slug('Lexus Trunk Spoiler'));
        $this->assertSame('headlights', $slug('Toyota Headlights Pair'));
        $this->assertSame('brake-pads', $slug('Ceramic Brake Pads'));
        $this->assertNull($resolver->resolve('Mystery widget'));
    }

    public function test_store_page_search_and_price_sort_use_the_same_rules(): void
    {
        $seller = $this->seller();
        $brand = Brand::create(['name' => 'Acme Q', 'slug' => 'acme-q']);
        $cheap = $this->product(['seller_id' => $seller->id, 'title' => 'Rotor Alpha', 'brand_id' => $brand->id, 'price' => 100, 'sale_price' => 20]);
        $mid = $this->product(['seller_id' => $seller->id, 'title' => 'Rotor Beta', 'price' => 50]);
        $url = route('sellers.show', $seller->sellerProfile);

        $this->get($url.'?q=acme')->assertOk()->assertSee('Rotor Alpha')->assertDontSee('Rotor Beta');
        $this->get($url.'?sort=price_asc')->assertOk()->assertSeeInOrder([$cheap->title, $mid->title]);
    }
}
