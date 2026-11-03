<?php

namespace Tests\Feature\Seller;

use App\Livewire\Seller\ShippingPolicies;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\ShippingPolicy;
use App\Models\User;
use App\Services\Shipping\ShippingEstimateService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ShippingPoliciesTest extends TestCase
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
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'Ship '.uniqid(), 'business_type' => 'llc', 'status' => 'approved', 'slug' => 'ship-'.uniqid()]);

        return $this->withTwoFactor($u);
    }

    private function product(User $seller, array $attrs = []): Product
    {
        return Product::create(array_merge([
            'seller_id' => $seller->id,
            'category_id' => Category::create(['name' => 'C'.uniqid(), 'slug' => 'c-'.uniqid()])->id,
            'title' => 'Rotor '.uniqid(), 'slug' => 'r-'.uniqid(),
            'price' => 40, 'quantity' => 10, 'status' => 'active', 'weight_lbs' => 3,
        ], $attrs));
    }

    public function test_component_creates_and_defaults_a_policy(): void
    {
        $seller = $this->seller();

        Livewire::actingAs($seller)->test(ShippingPolicies::class)
            ->set('name', 'Free 3-day')
            ->set('handlingDays', 3)
            ->set('flatRate', 0)
            ->set('freeOver', 25)
            ->set('isDefault', true)
            ->call('addSurcharge')
            ->set('surcharges.0.state', 'ak')
            ->set('surcharges.0.amount', 9.99)
            ->call('save')
            ->assertHasNoErrors();

        $policy = ShippingPolicy::where('seller_id', $seller->id)->firstOrFail();
        $this->assertTrue($policy->is_default);
        $this->assertSame(3, $policy->handling_days);
        $this->assertEquals(9.99, $policy->region_surcharges['AK']);
    }

    public function test_flat_rate_and_free_over_override_weight_pricing(): void
    {
        $seller = $this->seller();
        $policy = ShippingPolicy::create([
            'seller_id' => $seller->id, 'name' => 'Flat', 'is_default' => true,
            'handling_days' => 1, 'flat_rate' => 6.50, 'free_over' => 100,
        ]);
        $product = $this->product($seller, ['shipping_policy_id' => $policy->id, 'weight_lbs' => 40]);

        $svc = app(ShippingEstimateService::class);

        // under the free-over threshold → flat rate, not the heavy weight-tier
        $lines = collect([['product' => $product, 'quantity' => 1, 'line_total' => 40.0]]);
        $this->assertEquals(6.50, $svc->estimate($lines)['shipping']);

        // over the threshold → free
        $lines2 = collect([['product' => $product, 'quantity' => 3, 'line_total' => 120.0]]);
        $this->assertEquals(0.0, $svc->estimate($lines2)['shipping']);
    }

    public function test_regional_surcharge_applies_for_ship_to_state(): void
    {
        $seller = $this->seller();
        $policy = ShippingPolicy::create([
            'seller_id' => $seller->id, 'name' => 'Std', 'is_default' => true,
            'handling_days' => 2, 'flat_rate' => 8.00, 'region_surcharges' => ['AK' => 12.00],
        ]);
        $product = $this->product($seller, ['shipping_policy_id' => $policy->id]);
        $lines = collect([['product' => $product, 'quantity' => 1, 'line_total' => 40.0]]);

        $svc = app(ShippingEstimateService::class);
        $this->assertEquals(8.00, $svc->estimate($lines, null, 'CA')['shipping']);
        $this->assertEquals(20.00, $svc->estimate($lines, null, 'AK')['shipping']);
    }

    public function test_effective_handling_days_precedence(): void
    {
        $seller = $this->seller();
        $policy = ShippingPolicy::create(['seller_id' => $seller->id, 'name' => 'P', 'handling_days' => 5]);

        $a = $this->product($seller, ['shipping_policy_id' => $policy->id]);
        $this->assertSame(5, $a->effectiveHandlingDays());

        $b = $this->product($seller, ['shipping_policy_id' => $policy->id, 'handling_days' => 1]);
        $this->assertSame(1, $b->effectiveHandlingDays());

        $c = $this->product($seller);
        $this->assertSame(2, $c->effectiveHandlingDays());
    }
}
