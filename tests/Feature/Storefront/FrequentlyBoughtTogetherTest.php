<?php

namespace Tests\Feature\Storefront;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Recommendations\RecommendationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FrequentlyBoughtTogetherTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private Category $tail;

    private Category $bulbs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seller = User::factory()->create();
        $this->seller->assignRole('seller');
        SellerProfile::create(['user_id' => $this->seller->id, 'business_name' => 'S', 'business_type' => 'llc', 'status' => 'approved', 'slug' => 's-'.Str::random(5)]);
        $lighting = Category::firstOrCreate(['slug' => 'lighting'], ['name' => 'Lighting']);
        $this->tail = Category::firstOrCreate(['slug' => 'tail-lights'], ['name' => 'Tail Lights', 'parent_id' => $lighting->id]);
        $this->bulbs = Category::firstOrCreate(['slug' => 'light-bulbs'], ['name' => 'Light Bulbs', 'parent_id' => $lighting->id]);
    }

    private function vehicle(string $make, string $model, int $year): Vehicle
    {
        $mk = VehicleMake::firstOrCreate(['slug' => Str::slug($make)], ['name' => $make]);
        $md = VehicleModel::firstOrCreate(['vehicle_make_id' => $mk->id, 'slug' => Str::slug($model)], ['name' => $model]);

        return Vehicle::create(['vehicle_make_id' => $mk->id, 'vehicle_model_id' => $md->id, 'year' => $year]);
    }

    private function product(string $title, Category $cat, array $fits = []): Product
    {
        $p = Product::create(['seller_id' => $this->seller->id, 'category_id' => $cat->id, 'title' => $title, 'slug' => Str::slug($title).'-'.Str::random(4), 'price' => 50, 'quantity' => 5, 'status' => 'active']);
        $p->fitments()->attach(collect($fits)->pluck('id'));

        return $p;
    }

    private function order(array $products, OrderStatus $status = OrderStatus::Delivered): void
    {
        $o = Order::create(['order_number' => 'PM-'.Str::random(8), 'buyer_id' => User::factory()->create()->id, 'status' => $status, 'subtotal' => 100, 'total' => 100, 'shipping_address' => ['city' => 'X'], 'billing_address' => ['city' => 'X']]);
        foreach ($products as $p) {
            $o->items()->create(['seller_id' => $this->seller->id, 'product_id' => $p->id, 'product_title' => $p->title, 'quantity' => 1, 'unit_price' => 50, 'line_total' => 50, 'commission_rate' => 10, 'platform_fee_amount' => 5, 'seller_payout_amount' => 45, 'status' => $status]);
        }
    }

    public function test_only_parts_for_the_same_vehicle_from_real_orders_and_never_the_same_kind_of_part(): void
    {
        $f150 = $this->vehicle('Ford', 'F-150', 2012);
        $camry = $this->vehicle('Toyota', 'Camry', 2020);

        $page = $this->product('F-150 LED Tail Lights', $this->tail, [$f150]);
        $bulbs = $this->product('F-150 Reverse Bulbs', $this->bulbs, [$f150]);
        $camryBulbs = $this->product('Camry Bulbs', $this->bulbs, [$camry]);
        $otherTail = $this->product('Other F-150 Tail Lights', $this->tail, [$f150]);
        $cancelledOnly = $this->product('F-150 Fog Bulbs', $this->bulbs, [$f150]);

        $this->order([$page, $bulbs]);
        $this->order([$page, $camryBulbs]);                       // wrong car: never shown
        $this->order([$page, $otherTail]);                        // same kind of part: never shown
        $this->order([$page, $cancelledOnly], OrderStatus::Cancelled);   // not a real purchase

        $c = app(RecommendationService::class)->companions($page);
        $this->assertSame('Frequently bought together', $c['title']);
        $this->assertSame([$bulbs->id], $c['items']->pluck('id')->all());
    }

    public function test_without_purchases_it_shows_other_parts_for_the_same_car_under_an_honest_heading(): void
    {
        $f150 = $this->vehicle('Ford', 'F-150', 2012);
        $camry = $this->vehicle('Toyota', 'Camry', 2020);
        $page = $this->product('F-150 LED Tail Lights', $this->tail, [$f150]);
        $this->product('Camry Tail Lights', $this->tail, [$camry]);         // the old fallback showed these
        $this->product('Lexus Tail Lights', $this->tail, [$camry]);
        $fits = $this->product('F-150 Reverse Bulbs', $this->bulbs, [$f150]);

        $c = app(RecommendationService::class)->companions($page);
        $this->assertSame('More for your Ford F-150', $c['title']);
        $this->assertSame([$fits->id], $c['items']->pluck('id')->all());

        $this->get(route('products.show', $page->slug))->assertOk()
            ->assertSee('More for your Ford F-150')->assertSee('F-150 Reverse Bulbs')
            ->assertDontSee('Camry Tail Lights')->assertDontSee('Lexus Tail Lights');
    }

    public function test_nothing_that_fits_means_nothing_is_suggested(): void
    {
        $page = $this->product('F-150 LED Tail Lights', $this->tail, [$this->vehicle('Ford', 'F-150', 2012)]);
        $this->product('Camry Tail Lights', $this->tail, [$this->vehicle('Toyota', 'Camry', 2020)]);

        $this->assertTrue(app(RecommendationService::class)->companions($page)['items']->isEmpty());
    }
}
