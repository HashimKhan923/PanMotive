<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Jobs\ReleaseSellerPayout;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PayoutAdvance;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Payments\InstantPayoutService;
use App\Services\Seller\SellerScorecardService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MoneyPayoutsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seller(string $tier = 'standard'): User
    {
        $u = User::factory()->create();
        $u->assignRole('seller');
        SellerProfile::create([
            'user_id' => $u->id, 'business_name' => 'Acme '.uniqid(), 'business_type' => 'llc',
            'status' => 'approved', 'tier' => $tier,
            'stripe_account_id' => 'acct_test', 'stripe_onboarding_complete' => true,
        ]);

        return $this->withTwoFactor($u);
    }

    private function shippedItem(User $seller): OrderItem
    {
        $order = Order::create([
            'order_number' => 'PM-'.strtoupper(uniqid()), 'buyer_id' => User::factory()->create()->id,
            'subtotal' => 200, 'total' => 200,
            'shipping_address' => ['line1' => 'x'], 'billing_address' => ['line1' => 'x'],
            'status' => OrderStatus::Paid,
        ]);

        return $order->items()->create([
            'seller_id' => $seller->id,
            'product_id' => Product::create([
                'seller_id' => $seller->id,
                'category_id' => Category::create(['name' => 'C'.uniqid(), 'slug' => 'c-'.uniqid()])->id,
                'title' => 'Part', 'slug' => 'p-'.uniqid(), 'price' => 200, 'quantity' => 3, 'status' => 'active',
            ])->id,
            'product_title' => 'Part', 'quantity' => 1, 'unit_price' => 200, 'line_total' => 200,
            'commission_rate' => 10, 'platform_fee_amount' => 20, 'seller_payout_amount' => 180,
            'status' => OrderStatus::Shipped, 'shipped_at' => now(),
        ]);
    }

    public function test_instant_payout_charges_fee_and_records_advance(): void
    {
        $seller = $this->seller();
        $item = $this->shippedItem($seller);

        $this->actingAs($seller)
            ->post(route('seller.orders.instant-payout', $item))
            ->assertRedirect();

        $advance = PayoutAdvance::where('order_item_id', $item->id)->firstOrFail();
        $this->assertEquals(180.0, (float) $advance->gross);
        $this->assertEquals(2.70, (float) $advance->fee);   // 1.5% of 180
        $this->assertEquals(177.30, (float) $advance->net_paid);
        $this->assertSame('advanced', $advance->status);
    }

    public function test_top_rated_seller_gets_fee_free_instant_payout(): void
    {
        $seller = $this->seller('top_rated');
        $item = $this->shippedItem($seller);

        $quote = app(InstantPayoutService::class)->quote($item);
        $this->assertEquals(0.0, $quote['fee']);
        $this->assertEquals(180.0, $quote['net']);
    }

    public function test_release_job_recoups_advance_instead_of_paying_twice(): void
    {
        $seller = $this->seller();
        $item = $this->shippedItem($seller);
        app(InstantPayoutService::class)->advance($item);

        $item->update(['status' => OrderStatus::Delivered]);
        (new ReleaseSellerPayout($item->id))->handle(
            app(\App\Services\Payments\TransferPayoutService::class),
            app(\App\Services\Marketing\WalletService::class),
            app(\App\Services\Marketing\LoyaltyService::class),
        );

        $item->refresh();
        $this->assertSame(OrderStatus::PayoutReleased, $item->status);
        $this->assertNotNull($item->payout_released_at);
        $this->assertSame('recouped', PayoutAdvance::where('order_item_id', $item->id)->value('status'));
    }

    public function test_cannot_advance_another_sellers_order(): void
    {
        $me = $this->seller();
        $other = $this->seller();
        $item = $this->shippedItem($other);

        $this->actingAs($me)->post(route('seller.orders.instant-payout', $item))->assertForbidden();
    }

    public function test_tax_page_and_statement_download(): void
    {
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $product = Product::create([
            'seller_id' => $seller->id,
            'category_id' => Category::create(['name' => 'C', 'slug' => 'c-'.uniqid()])->id,
            'title' => 'Part', 'slug' => 'p-'.uniqid(), 'price' => 100, 'quantity' => 5, 'status' => 'active',
        ]);
        $order = Order::create([
            'order_number' => 'PM-'.strtoupper(uniqid()), 'buyer_id' => $buyer->id,
            'subtotal' => 100, 'total' => 100, 'shipping_address' => ['line1' => 'x'], 'billing_address' => ['line1' => 'x'],
            'status' => OrderStatus::Delivered,
        ]);
        $order->items()->create([
            'seller_id' => $seller->id, 'product_id' => $product->id, 'product_title' => 'Part',
            'quantity' => 1, 'unit_price' => 100, 'line_total' => 100, 'commission_rate' => 10,
            'platform_fee_amount' => 10, 'seller_payout_amount' => 90, 'status' => OrderStatus::Delivered,
        ]);

        $this->actingAs($seller)->get(route('seller.tax.index'))
            ->assertOk()->assertSee('Form 1099-K')->assertSee('$100.00');

        $this->actingAs($seller)->get(route('seller.tax.statement', ['year' => now()->year]))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_health_lights_reflect_metrics(): void
    {
        $seller = $this->seller();
        $health = app(SellerScorecardService::class)->healthLights($seller);

        $this->assertArrayHasKey('overall', $health);
        $this->assertContains($health['overall'], ['green', 'amber', 'red']);
        $this->assertCount(4, $health['signals']);

        $this->actingAs($seller)->get(route('seller.dashboard'))->assertOk()->assertSee('Store health');
    }
}
