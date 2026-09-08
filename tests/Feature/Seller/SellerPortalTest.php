<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Livewire\Seller\OnboardingWizard;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SellerPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function approvedSeller(): User
    {
        $user = User::factory()->create();
        $user->assignRole('seller');
        SellerProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Acme Parts',
            'business_type' => 'llc',
            'status' => 'approved',
        ]);

        return $this->withTwoFactor($user);
    }

    public function test_guest_is_redirected_to_login_from_seller_dashboard(): void
    {
        $this->get(route('seller.dashboard'))->assertRedirect(route('login'));
    }

    public function test_non_seller_is_redirected_to_onboarding(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('seller.dashboard'))
            ->assertRedirect(route('seller.onboarding.start'));
    }

    public function test_onboarding_wizard_renders_and_grants_seller_role(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('seller.onboarding.start'))->assertOk();

        $this->assertTrue($user->fresh()->hasRole('seller'));
    }

    public function test_seller_without_approval_is_redirected_to_pending(): void
    {
        $user = User::factory()->create();
        $user->assignRole('seller');
        SellerProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Acme',
            'business_type' => 'sole_prop',
            'status' => 'pending',
        ]);

        $this->actingAs($user)
            ->get(route('seller.dashboard'))
            ->assertRedirect(route('seller.onboarding.pending'));
    }

    public function test_approved_seller_can_view_dashboard_products_and_orders(): void
    {
        $seller = $this->approvedSeller();

        $this->actingAs($seller)->get(route('seller.dashboard'))->assertOk();
        $this->actingAs($seller)->get(route('seller.products.index'))->assertOk();
        $this->actingAs($seller)->get(route('seller.products.create'))->assertOk();
        $this->actingAs($seller)->get(route('seller.orders.index'))->assertOk();
    }

    public function test_suspended_seller_gets_403(): void
    {
        $seller = $this->approvedSeller();
        $seller->sellerProfile->update(['status' => 'suspended']);

        $this->actingAs($seller)->get(route('seller.dashboard'))->assertForbidden();
    }

    public function test_onboarding_business_step_creates_profile(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(OnboardingWizard::class)
            ->set('business_name', 'Bolt Depot')
            ->set('business_type', 'llc')
            ->call('saveBusinessInfo')
            ->assertSet('step', 2);

        $this->assertDatabaseHas('seller_profiles', [
            'user_id' => $user->id,
            'business_name' => 'Bolt Depot',
            'status' => 'pending',
        ]);
    }

    public function test_seller_can_accept_then_ship_an_order_item(): void
    {
        $seller = $this->approvedSeller();
        $item = $this->makeOrderItemFor($seller);

        $this->actingAs($seller)
            ->post(route('seller.orders.accept', $item))
            ->assertRedirect();

        $this->assertSame(OrderStatus::SellerAccepted, $item->fresh()->status);
        $this->assertDatabaseHas('order_status_history', [
            'order_item_id' => $item->id,
            'status' => 'seller_accepted',
        ]);

        $this->actingAs($seller)
            ->post(route('seller.orders.ship', $item), [
                'carrier' => 'USPS',
                'tracking_number' => '9400100000000000000000',
            ])
            ->assertRedirect();

        $fresh = $item->fresh();
        $this->assertSame(OrderStatus::Shipped, $fresh->status);
        $this->assertNotNull($fresh->shipped_at);
        $this->assertSame('USPS', $fresh->carrier);
    }

    public function test_seller_cannot_act_on_another_sellers_order_item(): void
    {
        $sellerA = $this->approvedSeller();
        $sellerB = $this->approvedSeller();
        $item = $this->makeOrderItemFor($sellerB);

        $this->actingAs($sellerA)
            ->post(route('seller.orders.accept', $item))
            ->assertForbidden();
    }

    public function test_ship_requires_carrier_and_tracking(): void
    {
        $seller = $this->approvedSeller();
        $item = $this->makeOrderItemFor($seller, OrderStatus::SellerAccepted);

        $this->actingAs($seller)
            ->from(route('seller.orders.index'))
            ->post(route('seller.orders.ship', $item), [])
            ->assertSessionHasErrors(['carrier', 'tracking_number']);
    }

    private function makeOrderItemFor(User $seller, OrderStatus $status = OrderStatus::Placed): OrderItem
    {
        $buyer = User::factory()->create();
        $order = Order::create([
            'order_number' => 'PM-'.strtoupper(uniqid()),
            'buyer_id' => $buyer->id,
            'subtotal' => 100,
            'total' => 100,
            'shipping_address' => ['line1' => '1 A St'],
            'billing_address' => ['line1' => '1 A St'],
            'status' => OrderStatus::Placed,
        ]);

        return $order->items()->create([
            'seller_id' => $seller->id,
            'product_id' => Product::create([
                'seller_id' => $seller->id,
                'category_id' => \App\Models\Category::create(['name' => 'X', 'slug' => 'x-'.uniqid()])->id,
                'title' => 'Widget',
                'slug' => 'widget-'.uniqid(),
                'price' => 100,
                'quantity' => 1,
            ])->id,
            'product_title' => 'Widget',
            'quantity' => 1,
            'unit_price' => 100,
            'line_total' => 100,
            'commission_rate' => 10,
            'platform_fee_amount' => 10,
            'seller_payout_amount' => 90,
            'status' => $status,
        ]);
    }
}
