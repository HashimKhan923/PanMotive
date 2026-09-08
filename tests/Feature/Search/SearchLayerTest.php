<?php

namespace Tests\Feature\Search;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Recommendations\RecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class SearchLayerTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'seller_id' => User::factory()->create()->id,
            'category_id' => Category::create(['name' => 'C', 'slug' => 'c-'.Str::random(5)])->id,
            'title' => 'Ceramic Brake Pad Set',
            'slug' => 'pad-'.Str::random(5),
            'price' => 89.99, 'quantity' => 5, 'status' => 'active',
        ], $overrides));
    }

    public function test_toSearchableArray_includes_filter_ids(): void
    {
        $p = $this->product(['part_number' => 'BP-9', 'oem_number' => 'OEM-9']);

        $doc = $p->toSearchableArray();

        foreach (['category_id', 'brand_id', 'seller_id', 'fitment_vehicle_ids', 'created_at', 'status'] as $key) {
            $this->assertArrayHasKey($key, $doc);
        }
        $this->assertSame($p->category_id, $doc['category_id']);
    }

    public function test_shop_page_renders_and_falls_back_to_db_without_meilisearch(): void
    {
        // SCOUT_DRIVER=null in phpunit.xml → Product::search() returns an empty
        // paginator; the component still renders. (The Meilisearch→DB catch is
        // exercised in dev where the driver is 'meilisearch' and no server runs.)
        $this->product();

        $this->get(route('products.index'))->assertOk()->assertSee('Shop');
    }

    public function test_semantic_fallback_is_skipped_without_an_api_key(): void
    {
        config()->set('services.anthropic.key', null);
        Http::preventStrayRequests();

        Livewire::test(\App\Livewire\Storefront\ProductBrowse::class)
            ->set('q', 'something with no matches at all')
            ->assertSet('fallbackNotice', '')
            ->assertOk();
    }

    public function test_sitemap_returns_xml_with_active_products(): void
    {
        $p = $this->product(['slug' => 'sitemap-widget']);

        $res = $this->get('/sitemap.xml');

        $res->assertOk();
        $this->assertStringContainsString('text/xml', $res->headers->get('Content-Type'));
        $res->assertSee('sitemap-widget', false);
        $res->assertSee('<urlset', false);
    }

    // ---------- recommendations ----------

    public function test_frequently_bought_with_derives_from_order_history(): void
    {
        $seller = User::factory()->create();
        $pads = $this->product(['seller_id' => $seller->id, 'slug' => 'pads-x']);
        $rotors = $this->product(['seller_id' => $seller->id, 'slug' => 'rotors-x', 'title' => 'Rotors']);
        $fluid = $this->product(['seller_id' => $seller->id, 'slug' => 'fluid-x', 'title' => 'Brake Fluid']);

        // Two orders that each contain pads + rotors; one also has fluid.
        foreach ([[$pads, $rotors], [$pads, $rotors, $fluid]] as $set) {
            $order = Order::create([
                'order_number' => 'PM-'.strtoupper(Str::random(8)),
                'buyer_id' => User::factory()->create()->id,
                'status' => OrderStatus::Paid, 'subtotal' => 100, 'total' => 100,
                'shipping_address' => ['x' => 1], 'billing_address' => ['x' => 1],
            ]);
            foreach ($set as $prod) {
                $order->items()->create([
                    'seller_id' => $seller->id, 'product_id' => $prod->id, 'product_title' => $prod->title,
                    'quantity' => 1, 'unit_price' => 50, 'line_total' => 50, 'commission_rate' => 10,
                    'platform_fee_amount' => 5, 'seller_payout_amount' => 45, 'status' => OrderStatus::Paid,
                ]);
            }
        }

        $recs = app(RecommendationService::class)->frequentlyBoughtWith($pads->id);

        $this->assertTrue($recs->contains('id', $rotors->id));
        $this->assertFalse($recs->contains('id', $pads->id));
        // rotors co-occurs twice, fluid once → rotors ranks first
        $this->assertSame($rotors->id, $recs->first()->id);
    }

    public function test_for_vehicle_returns_only_fitting_products(): void
    {
        $make = VehicleMake::create(['name' => 'Toyota', 'slug' => 'toyota-'.Str::random(4)]);
        $model = VehicleModel::create(['vehicle_make_id' => $make->id, 'name' => 'Camry', 'slug' => 'camry-'.Str::random(4)]);
        $vehicle = Vehicle::create([
            'vehicle_make_id' => $make->id, 'vehicle_model_id' => $model->id, 'year' => 2020,
        ]);

        $fits = $this->product(['slug' => 'fits-x']);
        $fits->fitments()->attach($vehicle->id);
        $this->product(['slug' => 'nofit-x']);

        $recs = app(RecommendationService::class)->forVehicle($vehicle->id);

        $this->assertTrue($recs->contains('id', $fits->id));
        $this->assertCount(1, $recs);
    }
}
