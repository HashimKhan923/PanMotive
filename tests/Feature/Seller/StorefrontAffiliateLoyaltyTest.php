<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Livewire\Seller\Affiliates;
use App\Livewire\Seller\StoreBuilder;
use App\Models\Affiliate;
use App\Models\AffiliateConversion;
use App\Models\AffiliateProgram;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\StoreLoyaltyAccount;
use App\Models\User;
use App\Services\Marketing\AffiliateService;
use App\Services\Marketing\StoreLoyaltyService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StorefrontAffiliateLoyaltyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seller(array $loyalty = []): User
    {
        $u = User::factory()->create();
        $u->assignRole('seller');
        SellerProfile::create([
            'user_id' => $u->id,
            'business_name' => 'Store '.uniqid(),
            'business_type' => 'llc',
            'status' => 'approved',
            'slug' => 'store-'.uniqid(),
            'loyalty' => $loyalty ?: null,
        ]);

        return $this->withTwoFactor($u);
    }

    private function product(User $seller): Product
    {
        return Product::create([
            'seller_id' => $seller->id,
            'category_id' => Category::create(['name' => 'C'.uniqid(), 'slug' => 'c-'.uniqid()])->id,
            'title' => 'Part '.uniqid(), 'slug' => 'p-'.uniqid(),
            'price' => 100, 'quantity' => 10, 'status' => 'active',
        ]);
    }

    private function deliveredOrder(User $seller, User $buyer, float $value = 100): Order
    {
        $order = Order::create([
            'order_number' => 'PM-'.strtoupper(uniqid()), 'buyer_id' => $buyer->id,
            'subtotal' => $value, 'total' => $value,
            'shipping_address' => ['line1' => 'x'], 'billing_address' => ['line1' => 'x'],
            'status' => OrderStatus::Delivered,
        ]);
        $order->items()->create([
            'seller_id' => $seller->id,
            'product_id' => $this->product($seller)->id,
            'product_title' => 'P', 'quantity' => 1, 'unit_price' => $value, 'line_total' => $value,
            'commission_rate' => 10, 'platform_fee_amount' => 10, 'seller_payout_amount' => $value - 10,
            'status' => OrderStatus::Delivered,
        ]);

        return $order;
    }

    public function test_store_builder_persists_theme(): void
    {
        $seller = $this->seller();

        Livewire::actingAs($seller)->test(StoreBuilder::class)
            ->set('accent', '#123ABC')
            ->set('heroHeadline', 'Performance brakes, shipped fast')
            ->set('heroSubtext', 'OEM and aftermarket')
            ->set('about', 'Family run since 2004.')
            ->call('addSection')
            ->set('sections.0.heading', 'Shipping')
            ->set('sections.0.body', 'Same-day dispatch before 2pm.')
            ->call('save')
            ->assertHasNoErrors();

        $theme = $seller->sellerProfile->fresh()->theme;
        $this->assertSame('#123ABC', $theme['accent']);
        $this->assertSame('Performance brakes, shipped fast', $theme['hero_headline']);
        $this->assertCount(1, $theme['sections']);
        $this->assertSame('Shipping', $theme['sections'][0]['heading']);
    }

    public function test_seller_store_page_renders_theme_and_loyalty_badge(): void
    {
        $seller = $this->seller(['enabled' => true, 'points_per_dollar' => 4, 'redeem_cents_per_point' => 1, 'min_redeem' => 300]);
        $seller->sellerProfile->update([
            'theme' => ['accent' => '#00AA55', 'hero_headline' => 'Welcome to the shop', 'sections' => [['heading' => 'Warranty', 'body' => '12 months.']]],
        ]);
        AffiliateProgram::create(['seller_id' => $seller->id, 'is_active' => true, 'commission_pct' => 12]);

        $this->get(route('sellers.show', $seller->sellerProfile))
            ->assertOk()
            ->assertSee('Welcome to the shop')
            ->assertSee('Warranty')
            ->assertSee('Earn 4 points per $1')
            ->assertSee('Join affiliate program')
            ->assertSee('--c-accent: 0 170 85', false);
    }

    public function test_affiliates_component_saves_program(): void
    {
        $seller = $this->seller();

        Livewire::actingAs($seller)->test(Affiliates::class)
            ->set('isActive', true)
            ->set('commissionPct', 15)
            ->set('cookieDays', 45)
            ->set('autoApprove', false)
            ->call('save')
            ->assertHasNoErrors();

        $program = AffiliateProgram::where('seller_id', $seller->id)->firstOrFail();
        $this->assertTrue($program->is_active);
        $this->assertEquals(15, (float) $program->commission_pct);
        $this->assertSame(45, $program->cookie_days);
        $this->assertFalse($program->auto_approve);
    }

    public function test_affiliate_join_auto_approves_and_go_link_sets_cookie(): void
    {
        $seller = $this->seller();
        $program = AffiliateProgram::create(['seller_id' => $seller->id, 'is_active' => true, 'commission_pct' => 10, 'auto_approve' => true]);
        $promoter = User::factory()->create();

        $affiliate = app(AffiliateService::class)->join($program, 'Promo Pete', 'pete@example.com', 'youtube', $promoter);
        $this->assertSame('approved', $affiliate->status);

        $this->get(route('affiliate.go', $affiliate))
            ->assertRedirect(route('sellers.show', $seller->sellerProfile))
            ->assertCookie('pm_aff', $affiliate->code);

        $this->assertSame(1, $affiliate->fresh()->clicks);
    }

    public function test_affiliate_manual_program_leaves_application_pending(): void
    {
        $seller = $this->seller();
        $program = AffiliateProgram::create(['seller_id' => $seller->id, 'is_active' => true, 'commission_pct' => 10, 'auto_approve' => false]);

        $affiliate = app(AffiliateService::class)->join($program, 'Jane', 'jane@example.com', null, User::factory()->create());

        $this->assertSame('pending', $affiliate->status);
    }

    public function test_affiliate_attribute_approve_and_payout_flow(): void
    {
        $seller = $this->seller();
        $program = AffiliateProgram::create(['seller_id' => $seller->id, 'is_active' => true, 'commission_pct' => 10, 'auto_approve' => true]);
        $promoter = User::factory()->create(['wallet_balance' => 0]);
        $buyer = User::factory()->create();

        $affiliate = app(AffiliateService::class)->join($program, 'P', 'p@example.com', null, $promoter);
        $order = $this->deliveredOrder($seller, $buyer, 200);

        $svc = app(AffiliateService::class);
        $conversion = $svc->attribute($order, $affiliate->code);

        $this->assertInstanceOf(AffiliateConversion::class, $conversion);
        $this->assertEquals(20.0, (float) $conversion->commission); // 10% of $200
        $this->assertSame('pending', $conversion->status);
        $this->assertEquals(20.0, (float) $affiliate->fresh()->earnings);

        // idempotent per affiliate+order
        $this->assertSame($conversion->id, $svc->attribute($order, $affiliate->code)->id);
        $this->assertSame(1, AffiliateConversion::count());

        // Funded by the seller: withheld from the order's payout right away.
        $this->assertEquals(20.0, (float) $order->items()->sum('affiliate_fee'));

        // Still inside the return window → not payable yet.
        $this->assertSame(0, $svc->approveDelivered());
        $this->travel(config('marketplace.marketing.affiliate_hold_days') + 1)->days();
        $this->assertSame(1, $svc->approveDelivered());
        $this->assertSame('approved', $conversion->fresh()->status);

        $paid = $svc->payOut($affiliate->fresh());
        $this->assertEquals(20.0, $paid);
        $this->assertSame('paid', $conversion->fresh()->status);
        $this->assertEquals(20.0, (float) $promoter->fresh()->wallet_balance);
    }

    public function test_affiliate_does_not_attribute_own_purchase(): void
    {
        $seller = $this->seller();
        $program = AffiliateProgram::create(['seller_id' => $seller->id, 'is_active' => true, 'commission_pct' => 10, 'auto_approve' => true]);
        $promoter = User::factory()->create();
        $affiliate = app(AffiliateService::class)->join($program, 'P', 'p@example.com', null, $promoter);

        $order = $this->deliveredOrder($seller, $promoter, 150);

        $this->assertNull(app(AffiliateService::class)->attribute($order, $affiliate->code));
    }

    public function test_store_loyalty_earns_points_idempotently(): void
    {
        $seller = $this->seller(['enabled' => true, 'points_per_dollar' => 5, 'redeem_cents_per_point' => 1, 'min_redeem' => 500]);
        $buyer = User::factory()->create();
        $order = $this->deliveredOrder($seller, $buyer, 60);

        $svc = app(StoreLoyaltyService::class);
        $svc->earnForOrder($order);
        $svc->earnForOrder($order); // second call must not double-award

        $account = StoreLoyaltyAccount::where('user_id', $buyer->id)->where('seller_id', $seller->id)->firstOrFail();
        $this->assertSame(300, $account->points); // 60 * 5
    }

    public function test_store_loyalty_does_not_earn_when_disabled(): void
    {
        $seller = $this->seller(['enabled' => false]);
        $buyer = User::factory()->create();

        app(StoreLoyaltyService::class)->earnForOrder($this->deliveredOrder($seller, $buyer, 100));

        $this->assertSame(0, StoreLoyaltyAccount::where('user_id', $buyer->id)->sum('points'));
    }

    public function test_store_loyalty_redeems_to_wallet(): void
    {
        $seller = $this->seller(['enabled' => true, 'points_per_dollar' => 5, 'redeem_cents_per_point' => 2, 'min_redeem' => 500]);
        $buyer = User::factory()->create(['wallet_balance' => 0]);

        $account = StoreLoyaltyAccount::create(['user_id' => $buyer->id, 'seller_id' => $seller->id, 'points' => 800, 'lifetime_points' => 800]);

        $value = app(StoreLoyaltyService::class)->redeemToWallet($buyer, $seller->id, 800);

        $this->assertEquals(16.0, $value); // 800 * 2c
        $this->assertSame(0, $account->fresh()->points);
        $this->assertEquals(16.0, (float) $buyer->fresh()->wallet_balance);
    }

    public function test_loyalty_redeem_route_credits_buyer(): void
    {
        $seller = $this->seller(['enabled' => true, 'points_per_dollar' => 5, 'redeem_cents_per_point' => 1, 'min_redeem' => 500]);
        $buyer = User::factory()->create(['wallet_balance' => 0]);
        StoreLoyaltyAccount::create(['user_id' => $buyer->id, 'seller_id' => $seller->id, 'points' => 600, 'lifetime_points' => 600]);

        $this->actingAs($buyer)
            ->post(route('loyalty.redeem', $seller->sellerProfile), ['points' => 600])
            ->assertRedirect();

        $this->assertEquals(6.0, (float) $buyer->fresh()->wallet_balance);
    }
}
