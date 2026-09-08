<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Models\AdminAlert;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Payments\PayoutReversalService;
use App\Services\Payments\RefundService;
use Database\Seeders\AdminPermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminConsoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(AdminPermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function admin(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $this->withTwoFactor($u);
    }

    private function pendingSeller(): SellerProfile
    {
        $user = $this->withTwoFactor(User::factory()->create());
        $user->assignRole('seller');

        return SellerProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Acme Parts',
            'business_type' => 'llc',
            'status' => 'pending',
        ]);
    }

    private function disputedItem(User $seller, array $overrides = []): OrderItem
    {
        $order = Order::create([
            'order_number' => 'PM-'.strtoupper(Str::random(10)),
            'buyer_id' => User::factory()->create()->id,
            'status' => OrderStatus::Paid,
            'subtotal' => 100, 'total' => 100,
            'shipping_address' => ['x' => 1], 'billing_address' => ['x' => 1],
            'stripe_payment_intent_id' => 'pi_'.Str::random(10),
        ]);

        return $order->items()->create(array_merge([
            'seller_id' => $seller->id,
            'product_id' => Product::create([
                'seller_id' => $seller->id,
                'category_id' => Category::create(['name' => 'C', 'slug' => 'c-'.Str::random(6)])->id,
                'title' => 'W', 'slug' => 'w-'.Str::random(6), 'price' => 100, 'quantity' => 1,
            ])->id,
            'product_title' => 'W', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100,
            'commission_rate' => 10, 'platform_fee_amount' => 10, 'seller_payout_amount' => 90,
            'status' => OrderStatus::ReturnRequested,
        ], $overrides));
    }

    // ---------- gate ----------

    public function test_guest_redirected_and_non_admin_gets_403(): void
    {
        // Guest first — actingAs() below persists for the rest of the test.
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_admin_roles_reach_dashboard(): void
    {
        foreach (['super_admin', 'support_agent', 'finance', 'catalog_manager'] as $role) {
            $this->actingAs($this->admin($role))->get(route('admin.dashboard'))->assertOk();
        }
    }

    // ---------- seller approval closes the Phase 3 gate ----------

    public function test_support_agent_approves_seller_and_unblocks_their_dashboard(): void
    {
        Notification::fake();
        $profile = $this->pendingSeller();

        // Seller is currently stuck behind EnsureSellerApproved.
        $this->actingAs($profile->user)->get(route('seller.dashboard'))
            ->assertRedirect(route('seller.onboarding.pending'));

        $this->actingAs($this->admin('support_agent'))
            ->post(route('admin.sellers.approve', $profile))
            ->assertRedirect();

        $profile->refresh();
        $this->assertSame('approved', $profile->status);
        $this->assertSame('verified', $profile->kyc_status);
        $this->assertDatabaseHas('activity_log', ['description' => 'Seller approved']);

        // ...and now they're through.
        $this->actingAs($profile->user)->get(route('seller.dashboard'))->assertOk();
    }

    public function test_finance_role_cannot_approve_sellers(): void
    {
        $this->actingAs($this->admin('finance'))
            ->post(route('admin.sellers.approve', $this->pendingSeller()))
            ->assertForbidden();
    }

    public function test_reject_requires_reason_and_sets_status(): void
    {
        $profile = $this->pendingSeller();
        $admin = $this->admin('super_admin');

        $this->actingAs($admin)->post(route('admin.sellers.reject', $profile))->assertSessionHasErrors('reason');

        $this->actingAs($admin)->post(route('admin.sellers.reject', $profile), ['reason' => 'Invalid EIN'])->assertRedirect();
        $this->assertSame('rejected', $profile->refresh()->status);
    }

    public function test_suspend_needs_the_suspend_permission(): void
    {
        $profile = $this->pendingSeller();
        $profile->update(['status' => 'approved']);

        $this->actingAs($this->admin('support_agent'))
            ->post(route('admin.sellers.suspend', $profile), ['reason' => 'Fraud'])
            ->assertForbidden();

        $this->actingAs($this->admin('super_admin'))
            ->post(route('admin.sellers.suspend', $profile), ['reason' => 'Fraud'])
            ->assertRedirect();
        $this->assertSame('suspended', $profile->refresh()->status);
    }

    // ---------- disputes ----------

    public function test_dispute_index_visible_to_support_agent(): void
    {
        $this->actingAs($this->admin('support_agent'))->get(route('admin.disputes.index'))->assertOk();
    }

    public function test_approve_return_refunds_when_payout_not_released(): void
    {
        $seller = $this->admin('support_agent'); // any user works as the seller here
        $item = $this->disputedItem($seller);

        $this->mock(RefundService::class)->shouldReceive('refund')->once();

        $this->actingAs($this->admin('finance'))
            ->post(route('admin.disputes.approve-return', $item))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('order_status_history', [
            'order_item_id' => $item->id,
            'note' => 'Return approved and refunded by admin',
        ]);
    }

    public function test_approve_return_is_refused_when_payout_already_released(): void
    {
        $seller = $this->admin('support_agent');
        $item = $this->disputedItem($seller, ['payout_released_at' => now()]);

        $this->actingAs($this->admin('finance'))
            ->from(route('admin.disputes.index'))
            ->post(route('admin.disputes.approve-return', $item))
            ->assertRedirect(route('admin.disputes.index'))
            ->assertSessionHasErrors('refund');
    }

    public function test_reverse_payout_path_for_released_items(): void
    {
        $seller = $this->admin('support_agent');
        $item = $this->disputedItem($seller, ['payout_released_at' => now()]);

        $this->mock(PayoutReversalService::class)->shouldReceive('reverse')->once();

        $this->actingAs($this->admin('finance'))
            ->post(route('admin.disputes.reverse-payout', $item), ['reason' => 'Approved after release'])
            ->assertRedirect();

        $this->assertSame(OrderStatus::Refunded, $item->refresh()->status);
        $this->assertDatabaseHas('order_status_history', [
            'order_item_id' => $item->id,
            'status' => 'payout_reversed_and_refunded',
        ]);
    }

    public function test_reverse_payout_rejects_items_without_a_released_payout(): void
    {
        $seller = $this->admin('support_agent');
        $item = $this->disputedItem($seller); // no payout_released_at

        $this->actingAs($this->admin('finance'))
            ->post(route('admin.disputes.reverse-payout', $item), ['reason' => 'x'])
            ->assertStatus(400);
    }

    // ---------- finance ----------

    public function test_finance_ledger_and_export_permission_gated(): void
    {
        $finance = $this->admin('finance');
        $catalog = $this->admin('catalog_manager');

        $this->actingAs($finance)->get(route('admin.finance.ledger'))->assertOk();
        $this->actingAs($catalog)->get(route('admin.finance.ledger'))->assertForbidden();

        $this->actingAs($finance)->get(route('admin.finance.export'))
            ->assertOk()
            ->assertDownload('commission-ledger-'.now()->format('Y-m-d').'.csv');
        $this->actingAs($catalog)->get(route('admin.finance.export'))->assertForbidden();
    }

    // ---------- alerts ----------

    public function test_alert_inbox_lists_and_resolves(): void
    {
        $alert = AdminAlert::create(['type' => 'payout_blocked', 'message' => 'Item #1 stuck']);
        $admin = $this->admin('super_admin');

        $this->actingAs($admin)->get(route('admin.alerts.index'))->assertOk()->assertSee('Item #1 stuck');

        $this->actingAs($admin)->post(route('admin.alerts.resolve', $alert))->assertRedirect();
        $this->assertNotNull($alert->refresh()->resolved_at);
    }
}
