<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Seller\SellerLedgerService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinanceReconciliationTest extends TestCase
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
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'Fin '.uniqid(), 'business_type' => 'llc', 'status' => 'approved', 'slug' => 'fin-'.uniqid()]);

        return $this->withTwoFactor($u);
    }

    private function soldItem(User $seller, float $line, OrderStatus $status = OrderStatus::Delivered): OrderItem
    {
        $order = Order::create([
            'order_number' => 'PM-'.strtoupper(uniqid()), 'buyer_id' => User::factory()->create()->id,
            'subtotal' => $line, 'total' => $line, 'shipping_address' => ['line1' => 'x'], 'billing_address' => ['line1' => 'x'],
            'status' => $status,
        ]);
        $product = Product::create([
            'seller_id' => $seller->id, 'category_id' => Category::create(['name' => 'C'.uniqid(), 'slug' => 'c-'.uniqid()])->id,
            'title' => 'Widget '.uniqid(), 'slug' => 'w-'.uniqid(), 'price' => $line, 'quantity' => 5, 'status' => 'active',
        ]);
        $fee = round($line * 0.1, 2);

        return $order->items()->create([
            'seller_id' => $seller->id, 'product_id' => $product->id, 'product_title' => $product->title,
            'quantity' => 1, 'unit_price' => $line, 'line_total' => $line,
            'commission_rate' => 10, 'platform_fee_amount' => $fee, 'seller_payout_amount' => $line - $fee,
            'status' => $status,
        ]);
    }

    public function test_pnl_nets_sales_fees_and_refunds(): void
    {
        $seller = $this->seller();
        $this->soldItem($seller, 200);           // fee 20, payout 180
        $this->soldItem($seller, 100);           // fee 10, payout 90
        $refunded = $this->soldItem($seller, 50); // fee 5, payout 45 — then refunded
        ReturnRequest::create([
            'order_item_id' => $refunded->id, 'user_id' => $refunded->order->buyer_id,
            'reason' => 'defective', 'status' => 'refunded',
        ]);
        // The real refund flow (ReturnService / BuyerRefundService) marks the line refunded.
        $refunded->update(['status' => \App\Enums\OrderStatus::Refunded]);

        $pnl = app(SellerLedgerService::class)->pnl($seller->id, now()->subDays(2), now()->addDay());

        $this->assertEquals(350.0, $pnl['gross_sales']);
        $this->assertEquals(50.0, $pnl['refunds']);
        $this->assertEquals(300.0, $pnl['net_sales']);
        // fees on the 3 sales (35) minus fee handed back on the refund (5) = 30
        $this->assertEquals(30.0, $pnl['platform_fees']);
        // 180 + 90 + 45 (sale nets) − 45 (refund net) = 270
        $this->assertEquals(270.0, $pnl['net_earnings']);
        $this->assertSame(3, $pnl['orders']);
    }

    public function test_ledger_page_and_csv_export(): void
    {
        $seller = $this->seller();
        $this->soldItem($seller, 120);

        $this->actingAs($seller)->get(route('seller.finance.ledger'))
            ->assertOk()
            ->assertSee('Profit &amp; loss', false)
            ->assertSee('Net earnings');

        $res = $this->actingAs($seller)->get(route('seller.finance.export', ['period' => '30d']));
        $res->assertOk();
        $this->assertStringContainsString('Date,Type,Reference,Description,Gross,Fee,Net', $res->streamedContent());
        $this->assertStringContainsString('sale', $res->streamedContent());
    }

    public function test_ledger_is_gated_by_finances_ability(): void
    {
        $owner = $this->seller();
        $viewerUser = $this->withTwoFactor(User::factory()->create(['email' => 'fv@example.com']));
        \App\Models\SellerTeamMember::create([
            'owner_id' => $owner->id, 'user_id' => $viewerUser->id, 'email' => 'fv@example.com',
            'role' => 'staff', 'abilities' => ['orders.view', 'products.view'], 'status' => 'active',
        ]);

        $this->actingAs($viewerUser)->get(route('seller.finance.ledger'))->assertForbidden();
    }
}
