<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Livewire\Seller\Customers;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Seller\CustomerInsightsService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CustomerLocationTest extends TestCase
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
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'Shop '.uniqid(), 'business_type' => 'llc', 'status' => 'approved', 'slug' => 's-'.uniqid()]);

        return $this->withTwoFactor($u);
    }

    private function buy(User $seller, User $buyer, array $address, string $when): void
    {
        $product = Product::create([
            'seller_id' => $seller->id, 'category_id' => Category::create(['name' => 'C', 'slug' => 'c-'.Str::random(6)])->id,
            'title' => 'Part '.Str::random(5), 'slug' => 'p-'.Str::random(8), 'price' => 50, 'quantity' => 10, 'status' => 'active',
        ]);
        $order = Order::create([
            'order_number' => 'PM-'.strtoupper(Str::random(10)), 'buyer_id' => $buyer->id, 'status' => OrderStatus::Paid,
            'subtotal' => 50, 'shipping_total' => 0, 'discount_total' => 0, 'credits_used' => 0, 'total' => 50,
            'shipping_address' => $address, 'billing_address' => $address, 'placed_at' => $when,
        ]);
        $item = $order->items()->create([
            'seller_id' => $seller->id, 'product_id' => $product->id, 'product_title' => $product->title, 'quantity' => 1,
            'unit_price' => 50, 'line_total' => 50, 'commission_rate' => 10, 'platform_fee_amount' => 5, 'seller_payout_amount' => 45,
            'status' => OrderStatus::Delivered,
        ]);
        $item->forceFill(['created_at' => $when])->save();
    }

    public function test_customers_list_shows_the_latest_ship_to_place_with_a_flag(): void
    {
        $seller = $this->seller();
        $mover = User::factory()->create(['name' => 'Kevin Haynie']);
        $this->buy($seller, $mover, ['city' => 'Austin', 'state' => 'TX', 'country' => 'US'], now()->subMonth());
        $this->buy($seller, $mover, ['city' => 'Fort Hood', 'state' => 'TX', 'country' => 'United States'], now()->subDay());
        $abroad = User::factory()->create(['name' => 'Jean Tremblay']);
        $this->buy($seller, $abroad, ['city' => 'Toronto', 'state' => 'ON', 'country' => 'CA'], now()->subDays(2));

        Livewire::actingAs($seller)->test(Customers::class)
            ->assertSee('Location')
            ->assertSee('Fort Hood, TX, US')
            ->assertDontSee('Austin, TX')
            ->assertSee('aria-label="United States"', false)
            ->assertSee('Toronto, ON, CA')
            ->assertSee('aria-label="CA"', false);

        // Another store's sales never leak in.
        $this->assertSame([], app(CustomerInsightsService::class)->lastLocations($this->seller()->id, [$mover->id]));
    }

    public function test_country_names_are_normalised(): void
    {
        foreach (['US' => 'US', 'usa' => 'US', 'United States' => 'US', 'Canada' => 'CA', 'mx' => 'MX', 'Narnia' => null, '' => null] as $in => $out) {
            $this->assertSame($out, CustomerInsightsService::countryCode($in), "for '{$in}'");
        }
    }
}
