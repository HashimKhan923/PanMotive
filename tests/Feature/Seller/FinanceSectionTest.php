<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Jobs\ReleaseSellerPayout;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Seller\SellerLedgerService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinanceSectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seller(array $profile = []): User
    {
        $u = User::factory()->create();
        $u->assignRole('seller');
        SellerProfile::create(array_merge(['user_id' => $u->id, 'business_name' => 'Ledger Parts', 'business_type' => 'llc', 'status' => 'approved', 'slug' => 's-'.uniqid()], $profile));

        return $this->withTwoFactor($u);
    }

    private function line(User $seller, OrderStatus $status, float $total = 100, array $extra = []): OrderItem
    {
        $product = Product::create([
            'seller_id' => $seller->id, 'category_id' => Category::create(['name' => 'C', 'slug' => 'c-'.Str::random(6)])->id,
            'title' => 'Rotor '.Str::random(3), 'slug' => 'p-'.Str::random(8), 'price' => $total, 'quantity' => 5, 'status' => 'active',
        ]);
        $order = Order::create([
            'order_number' => 'PM-'.strtoupper(Str::random(10)), 'buyer_id' => User::factory()->create()->id, 'status' => OrderStatus::Paid,
            'subtotal' => $total, 'total' => $total, 'shipping_address' => ['line1' => 'x'], 'billing_address' => ['line1' => 'x'], 'placed_at' => now(),
        ]);

        return $order->items()->create(array_merge([
            'seller_id' => $seller->id, 'product_id' => $product->id, 'product_title' => $product->title,
            'quantity' => 1, 'unit_price' => $total, 'line_total' => $total, 'commission_rate' => 10,
            'platform_fee_amount' => $total / 10, 'seller_payout_amount' => $total * 0.9, 'status' => $status,
        ], $extra));
    }

    public function test_every_refund_shows_in_the_ledger_not_just_returns(): void
    {
        $seller = $this->seller();
        $kept = $this->line($seller, OrderStatus::Delivered, 200);
        // Refunded through a claim — no return request exists.
        $claimRefund = $this->line($seller, OrderStatus::Refunded, 80);
        $claimRefund->statusHistory()->create(['status' => OrderStatus::Refunded->value, 'note' => 'Claim refunded']);

        $ledger = app(SellerLedgerService::class);
        $entries = $ledger->entries($seller->id, now()->subDay(), now()->addDay());
        $this->assertCount(1, $entries->where('type', 'refund'));

        $pnl = $ledger->pnl($seller->id, now()->subDay(), now()->addDay());
        $this->assertEquals(80.0, $pnl['refunds']);
        $this->assertEquals(180.0, $pnl['net_earnings'], 'the refunded sale nets to zero; only the kept sale earns');
    }

    public function test_affiliate_commission_gets_its_own_ledger_line_and_rows_add_up(): void
    {
        $seller = $this->seller();
        // $100 sale, $10 fee, $5 affiliate commission withheld → payout $85.
        $this->line($seller, OrderStatus::Delivered, 100, ['affiliate_fee' => 5, 'seller_payout_amount' => 85]);

        $ledger = app(SellerLedgerService::class);
        $entries = $ledger->entries($seller->id, now()->subDay(), now()->addDay());
        $this->assertEquals(-5.0, $entries->firstWhere('type', 'affiliate_fee')['net']);
        $this->assertEquals(85.0, round($entries->sum('net'), 2), 'rows sum to what the seller is actually paid');
        $this->assertEquals(5.0, $ledger->pnl($seller->id, now()->subDay(), now()->addDay())['affiliate_fees']);
    }

    public function test_payout_status_shows_holds_and_reversals(): void
    {
        $seller = $this->seller();
        $this->line($seller, OrderStatus::Shipped, 100);
        $this->line($seller, OrderStatus::ReturnRequested, 50);
        $this->line($seller, OrderStatus::Refunded, 40, ['payout_released_at' => now()->subDays(3)]);
        $this->line($seller, OrderStatus::PayoutReleased, 200, ['payout_released_at' => now()]);

        $s = app(SellerLedgerService::class)->payoutStatus($seller->id);
        $this->assertEquals(90.0, $s['in_escrow']);
        $this->assertEquals(45.0, $s['on_hold']);
        $this->assertEquals(36.0, $s['reversed']);
        $this->assertEquals(180.0, $s['released']);
    }

    public function test_payouts_page_explains_what_is_blocking_money(): void
    {
        config()->set('marketplace.kyc.enforce_payouts', true);
        $seller = $this->seller(['stripe_account_id' => 'acct_1', 'stripe_onboarding_complete' => true]);
        $this->line($seller, OrderStatus::Shipped, 100, ['shipped_at' => now()]);

        $this->actingAs($seller)->get(route('seller.payouts.index'))
            ->assertOk()
            ->assertSee('Verify your identity')
            ->assertSee('Waiting on: Verify your identity');
    }

    public function test_out_for_delivery_items_auto_confirm_too(): void
    {
        Queue::fake();
        $seller = $this->seller();
        $stuck = $this->line($seller, OrderStatus::OutForDelivery, 100, ['shipped_at' => now()->subDays(10)]);

        $this->artisan('orders:auto-confirm-deliveries')->assertSuccessful();

        $this->assertSame(OrderStatus::Delivered, $stuck->fresh()->status);
        Queue::assertPushed(ReleaseSellerPayout::class, fn ($job) => $job->orderItemId === $stuck->id);
    }

    public function test_tax_page_uses_federal_1099k_rule_and_honest_sales_tax_copy(): void
    {
        $seller = $this->seller();
        $this->line($seller, OrderStatus::Shipped, 300);      // paid but not delivered yet — still a payment
        $this->line($seller, OrderStatus::Delivered, 200);
        $this->line($seller, OrderStatus::Placed, 999);       // never paid
        $this->line($seller, OrderStatus::Cancelled, 500);    // cancelled before shipping

        $this->actingAs($seller)->get(route('seller.tax.index'))
            ->assertOk()
            ->assertSee('$500.00 / $20,000', false)
            ->assertSee('2 / 200')
            ->assertSee('does not yet calculate sales tax')
            ->assertDontSee('collects and remits');

        config()->set('marketplace.sales_tax.collecting', true);
        $this->actingAs($seller)->get(route('seller.tax.index'))->assertSee('collects and remits');
    }

    public function test_monthly_statement_includes_fees_and_refunds(): void
    {
        $seller = $this->seller();
        $this->line($seller, OrderStatus::Delivered, 100, ['offsite_ad_fee' => 12, 'seller_payout_amount' => 78]);
        $r = $this->line($seller, OrderStatus::Refunded, 60);
        $r->statusHistory()->create(['status' => OrderStatus::Refunded->value, 'note' => 'refunded']);

        $csv = $this->actingAs($seller)->get(route('seller.tax.statement', ['year' => now()->year, 'month' => now()->month]))
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('offsite_ad_fee', $csv);
        $this->assertStringContainsString('refund', $csv);
    }
}
