<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Livewire\Seller\AccountHealth;
use App\Models\BuyerClaim;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PolicyViolation;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Notifications\BuyerClaimFiled;
use App\Services\Seller\AccountHealthService;
use Database\Seeders\AdminPermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AccountHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(AdminPermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seller(): User
    {
        $u = User::factory()->create();
        $u->assignRole('seller');
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'AH '.uniqid(), 'business_type' => 'llc', 'status' => 'approved', 'slug' => 'ah-'.uniqid()]);

        return $this->withTwoFactor($u);
    }

    private function item(User $seller, User $buyer, OrderStatus $status = OrderStatus::Delivered): OrderItem
    {
        $order = Order::create([
            'order_number' => 'PM-'.strtoupper(uniqid()), 'buyer_id' => $buyer->id,
            'subtotal' => 80, 'total' => 80, 'shipping_address' => ['line1' => 'x'], 'billing_address' => ['line1' => 'x'],
            'status' => $status,
        ]);
        $product = Product::create([
            'seller_id' => $seller->id, 'category_id' => Category::create(['name' => 'C'.uniqid(), 'slug' => 'c-'.uniqid()])->id,
            'title' => 'P '.uniqid(), 'slug' => 'p-'.uniqid(), 'price' => 80, 'quantity' => 5, 'status' => 'active',
        ]);

        return $order->items()->create([
            'seller_id' => $seller->id, 'product_id' => $product->id, 'product_title' => $product->title,
            'quantity' => 1, 'unit_price' => 80, 'line_total' => 80,
            'commission_rate' => 10, 'platform_fee_amount' => 8, 'seller_payout_amount' => 72, 'status' => $status,
        ]);
    }

    public function test_buyer_files_claim_and_seller_is_notified(): void
    {
        Notification::fake();
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $item = $this->item($seller, $buyer);

        $this->actingAs($buyer)->post(route('orders.claim', $item), [
            'reason' => 'not_as_described', 'detail' => 'The bracket is the wrong size for the listing.',
        ])->assertRedirect();

        $claim = BuyerClaim::firstOrFail();
        $this->assertSame($seller->id, $claim->seller_id);
        $this->assertSame('open', $claim->status);
        $this->assertNotNull($claim->respond_by);
        Notification::assertSentTo($seller, BuyerClaimFiled::class);
    }

    public function test_duplicate_open_claim_is_rejected(): void
    {
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $item = $this->item($seller, $buyer);

        $this->actingAs($buyer)->post(route('orders.claim', $item), ['reason' => 'damaged', 'detail' => 'Cracked on arrival.']);
        $this->actingAs($buyer)->from(route('orders.show', $item->order))
            ->post(route('orders.claim', $item), ['reason' => 'damaged', 'detail' => 'again'])
            ->assertSessionHasErrors('reason');

        $this->assertSame(1, BuyerClaim::count());
    }

    public function test_seller_responds_via_component(): void
    {
        $seller = $this->seller();
        $item = $this->item($seller, User::factory()->create());
        $claim = BuyerClaim::create([
            'order_item_id' => $item->id, 'buyer_id' => $item->order->buyer_id, 'seller_id' => $seller->id,
            'reason' => 'not_received', 'detail' => 'nothing came', 'amount' => 80, 'status' => 'open',
        ]);

        Livewire::actingAs($seller)->test(AccountHealth::class)
            ->call('startResponse', $claim->id)
            ->set('claimResponse', 'Tracking shows delivered on the 3rd, sending proof and a reship.')
            ->call('submitResponse')
            ->assertHasNoErrors();

        $this->assertSame('seller_responded', $claim->fresh()->status);
    }

    public function test_stale_claim_escalates_and_creates_a_violation(): void
    {
        $seller = $this->seller();
        $item = $this->item($seller, User::factory()->create());
        $claim = BuyerClaim::create([
            'order_item_id' => $item->id, 'buyer_id' => $item->order->buyer_id, 'seller_id' => $seller->id,
            'reason' => 'no_seller_response', 'detail' => 'silence', 'amount' => 80, 'status' => 'open',
            'respond_by' => now()->subHour(),
        ]);

        $this->assertSame(1, app(AccountHealthService::class)->escalateStaleClaims());
        $this->assertSame('escalated', $claim->fresh()->status);
        $this->assertDatabaseHas('policy_violations', ['seller_id' => $seller->id, 'type' => 'unanswered_claim']);
        $this->assertDatabaseHas('admin_alerts', ['type' => 'claim_escalated']);
    }

    public function test_appeal_flow_and_standing(): void
    {
        $seller = $this->seller();
        $v = PolicyViolation::create([
            'seller_id' => $seller->id, 'type' => 'late_shipment', 'severity' => 'severe',
            'title' => 'Repeated late shipments', 'status' => 'active',
        ]);

        Livewire::actingAs($seller)->test(AccountHealth::class)
            ->call('startAppeal', $v->id)
            ->set('appealText', 'Carrier strike in the region delayed three shipments; here are the case numbers.')
            ->call('submitAppeal')
            ->assertHasNoErrors();

        $this->assertSame('appealed', $v->fresh()->status);

        $standing = app(AccountHealthService::class)->standing($seller->fresh());
        $this->assertSame('at_risk', $standing['level']);          // a severe violation still counts while appealed
        $this->assertSame(1, $standing['severe']);
    }

    public function test_admin_grants_claim_credits_buyer_and_records_violation(): void
    {
        $seller = $this->seller();
        $buyer = User::factory()->create(['wallet_balance' => 0]);
        $item = $this->item($seller, $buyer);
        $claim = BuyerClaim::create([
            'order_item_id' => $item->id, 'buyer_id' => $buyer->id, 'seller_id' => $seller->id,
            'reason' => 'not_received', 'detail' => 'x', 'amount' => 80, 'status' => 'escalated',
        ]);

        $admin = $this->withTwoFactor(tap(User::factory()->create())->assignRole('super_admin'));

        $this->actingAs($admin)->post(route('admin.claims.decide', $claim), ['decision' => 'grant', 'note' => 'No proof of delivery.'])
            ->assertRedirect();

        $this->assertSame('granted', $claim->fresh()->status);
        $this->assertEquals(80.0, (float) $buyer->fresh()->wallet_balance);
        $this->assertDatabaseHas('policy_violations', ['seller_id' => $seller->id, 'type' => 'claim_granted']);
    }

    public function test_health_page_renders(): void
    {
        $seller = $this->seller();
        $this->actingAs($seller)->get(route('seller.account-health.index'))
            ->assertOk()
            ->assertSee('Account health')
            ->assertSee('Buyer claims');
    }
}
