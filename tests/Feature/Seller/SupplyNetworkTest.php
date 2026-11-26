<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Jobs\ReleaseResellerMargin;
use App\Jobs\ReleaseSellerPayout;
use App\Livewire\Seller\SupplyNetwork;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\SupplyConnection;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Marketing\LoyaltyService;
use App\Services\Marketing\WalletService;
use App\Services\Orders\BuyerRefundService;
use App\Services\Payments\CheckoutPaymentService;
use App\Services\Payments\PayoutReversalService;
use App\Services\Payments\RefundService;
use App\Services\Payments\TransferPayoutService;
use App\Services\Seller\SellerLedgerService;
use App\Services\Seller\SupplyNetworkService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Stripe\PaymentIntent;
use Stripe\Transfer;
use Tests\TestCase;

class SupplyNetworkTest extends TestCase
{
    use RefreshDatabase;

    private User $supplier;

    private User $reseller;

    private Product $part;

    private Vehicle $civic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Notification::fake();

        $this->supplier = $this->store('AutoPro Warehouse', ['supply_enabled' => true, 'supply_margin_pct' => 20]);
        $this->reseller = $this->store('Hirev Sports');

        $make = VehicleMake::create(['name' => 'Honda', 'slug' => 'honda']);
        $model = VehicleModel::create(['vehicle_make_id' => $make->id, 'name' => 'Civic', 'slug' => 'civic']);
        $this->civic = Vehicle::create(['vehicle_make_id' => $make->id, 'vehicle_model_id' => $model->id, 'year' => 2020]);

        $this->part = Product::create([
            'seller_id' => $this->supplier->id,
            'category_id' => Category::create(['name' => 'Tail Lights', 'slug' => 'tl-'.Str::random(4)])->id,
            'title' => 'Civic LED Tail Lights', 'slug' => 'civic-tail-'.Str::random(5),
            'price' => 200, 'quantity' => 8, 'status' => 'active', 'handling_days' => 2,
        ]);
        $this->part->fitments()->attach($this->civic->id);
    }

    private function store(string $name, array $profile = []): User
    {
        $u = $this->withTwoFactor(User::factory()->create());
        $u->assignRole('seller');
        SellerProfile::create($profile + [
            'user_id' => $u->id, 'business_name' => $name, 'business_type' => 'llc', 'status' => 'approved',
            'slug' => Str::slug($name).'-'.Str::random(4), 'stripe_account_id' => 'acct_'.Str::random(6), 'stripe_onboarding_complete' => true,
        ]);

        return $u->fresh();
    }

    private function supply(): SupplyNetworkService
    {
        return app(SupplyNetworkService::class);
    }

    private function connectAndAdd(): Product
    {
        $conn = $this->supply()->request($this->reseller, $this->supplier, 'We sell lots of Civic parts');
        $this->supply()->approve($conn);

        return $this->supply()->addToStore($this->reseller, $this->part);
    }

    private function buy(Product $listing, int $qty = 1, array $extra = []): Order
    {
        $this->mock(CheckoutPaymentService::class)->shouldReceive('createPaymentIntent')
            ->andReturn(PaymentIntent::constructFrom(['id' => 'pi_sn_'.Str::random(5), 'client_secret' => 'sec']));
        $buyer = User::factory()->create();
        Cart::create(['user_id' => $buyer->id])->items()->create(['product_id' => $listing->id, 'quantity' => $qty]);
        $this->actingAs($buyer)->post(route('checkout.store'), $extra + [
            'name' => 'Jane', 'line1' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '73301', 'country' => 'US', 'phone' => '5125550100',
        ])->assertOk();

        return Order::latest('id')->firstOrFail();
    }

    // ---------------------------------------------------------------- connecting

    public function test_a_reseller_asks_the_supplier_approves_and_both_are_told(): void
    {
        $conn = $this->supply()->request($this->reseller, $this->supplier, 'Hi');
        $this->assertSame('pending', $conn->status);
        $this->assertSame(20.0, (float) $conn->margin_pct);

        $this->supply()->approve($conn, 25);
        $this->assertSame('approved', $conn->fresh()->status);
        $this->assertSame(25.0, (float) $conn->fresh()->margin_pct);
        Notification::assertSentTo($this->supplier, \App\Notifications\AccountNotice::class);
        Notification::assertSentTo($this->reseller, \App\Notifications\AccountNotice::class);
    }

    public function test_auto_approval_margin_limits_and_closed_stores(): void
    {
        $this->supplier->sellerProfile->update(['supply_auto_approve' => true, 'supply_margin_pct' => 3]);
        $conn = $this->supply()->request($this->reseller, $this->supplier);
        $this->assertSame('approved', $conn->status);
        $this->assertSame(SupplyNetworkService::MIN_MARGIN, (float) $conn->margin_pct);   // never below the minimum

        $closed = $this->store('Closed Store');
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->supply()->request($this->reseller, $closed);
    }

    // ---------------------------------------------------------------- listings

    public function test_adding_a_product_creates_a_mirrored_listing_with_fitment_and_options(): void
    {
        $v = $this->part->variants()->create(['label' => 'Color: Smoke', 'options' => ['Color' => 'Smoke'], 'price' => 210, 'quantity' => 3, 'is_active' => true]);
        $copy = $this->connectAndAdd();

        $this->assertSame($this->reseller->id, $copy->seller_id);
        $this->assertSame($this->part->id, $copy->source_product_id);
        $this->assertSame('Civic LED Tail Lights', $copy->title);
        $this->assertEquals(200, (float) $copy->price);
        $this->assertTrue($copy->fitments()->where('vehicles.id', $this->civic->id)->exists());
        $mine = $copy->variants()->first();
        $this->assertSame($v->id, $mine->source_variant_id);
        $this->assertSame(3, $mine->quantity);
        $this->assertTrue($copy->isListed());
    }

    public function test_supplier_changes_reach_copies_and_resellers_cannot_change_them(): void
    {
        $copy = $this->connectAndAdd();

        $this->part->update(['price' => 180, 'quantity' => 2]);
        $this->assertEquals(180, (float) $copy->fresh()->price);
        $this->assertSame(2, $copy->fresh()->quantity);

        $copy->fresh()->update(['price' => 1, 'quantity' => 99, 'title' => 'Hacked']);
        $this->assertEquals(180, (float) $copy->fresh()->price);
        $this->assertSame(2, $copy->fresh()->quantity);
        $this->assertSame('Civic LED Tail Lights', $copy->fresh()->title);
    }

    public function test_copies_show_once_in_the_shop_but_on_the_resellers_store_and_page(): void
    {
        $copy = $this->connectAndAdd();

        $this->assertSame(1, Product::listed()->original()->where('title', 'Civic LED Tail Lights')->count());
        $this->get(route('products.index', ['q' => 'Civic LED']))->assertOk();

        $this->get(route('sellers.show', $this->reseller->sellerProfile))->assertOk()->assertSee('Civic LED Tail Lights');
        $this->get(route('products.show', $copy->slug))->assertOk()
            ->assertSee('Sold by')->assertSee('Hirev Sports')
            ->assertSee('AutoPro Warehouse')
            ->assertSee('rel="canonical" href="'.route('products.show', $this->part->slug).'"', false);
    }

    public function test_ending_the_partnership_or_switching_off_takes_copies_off_sale(): void
    {
        $copy = $this->connectAndAdd();
        $conn = SupplyConnection::firstOrFail();

        $this->supply()->end($conn, $this->supplier);
        $this->assertFalse($copy->fresh()->isListed());
        $this->assertSame(0, Product::listed()->whereKey($copy->id)->count());

        $this->supply()->approve($conn->fresh());
        $this->assertTrue($copy->fresh()->isListed());
        $this->supplier->sellerProfile->update(['supply_enabled' => false]);
        $this->assertSame(0, Product::listed()->whereKey($copy->id)->count());
    }

    // ---------------------------------------------------------------- orders & money

    public function test_checkout_routes_the_line_to_the_supplier_and_splits_the_money(): void
    {
        $copy = $this->connectAndAdd();
        $order = $this->buy($copy, 2);

        $item = $order->items()->firstOrFail();
        $this->assertSame($this->supplier->id, $item->seller_id);       // supplier ships
        $this->assertSame($this->part->id, $item->product_id);
        $this->assertSame($this->reseller->id, $item->reseller_id);
        $this->assertSame($copy->id, $item->listing_product_id);
        $this->assertEquals(400, (float) $item->line_total);
        $this->assertEquals(80, (float) $item->reseller_margin);          // 20%
        $this->assertEquals(round(400 - (float) $item->platform_fee_amount - 80, 2), (float) $item->seller_payout_amount);

        // Stock comes off the supplier's product, and the copy follows.
        $this->assertSame(6, $this->part->fresh()->quantity);
        $this->assertSame(6, $copy->fresh()->quantity);
    }

    public function test_the_resellers_own_coupon_never_discounts_the_suppliers_part(): void
    {
        $copy = $this->connectAndAdd();
        Coupon::create(['code' => 'HIREV10', 'type' => 'percentage', 'value' => 10, 'is_active' => true, 'seller_id' => $this->reseller->id]);
        $this->mock(CheckoutPaymentService::class)->shouldReceive('createPaymentIntent')
            ->andReturn(PaymentIntent::constructFrom(['id' => 'pi_c', 'client_secret' => 'sec']));
        $buyer = User::factory()->create();
        Cart::create(['user_id' => $buyer->id])->items()->create(['product_id' => $copy->id, 'quantity' => 1]);
        $this->actingAs($buyer)->post(route('checkout.apply-coupon'), ['code' => 'HIREV10']);
        $this->actingAs($buyer)->post(route('checkout.store'), ['name' => 'J', 'line1' => '1', 'city' => 'A', 'state' => 'TX', 'postal_code' => '73301', 'country' => 'US', 'phone' => '5125550100']);

        $item = OrderItem::latest('id')->firstOrFail();
        $this->assertEquals(0, (float) $item->discount_amount);
        $this->assertEquals(40, (float) $item->reseller_margin);
    }

    public function test_after_delivery_the_supplier_then_the_reseller_is_paid_once(): void
    {
        $copy = $this->connectAndAdd();
        $item = $this->buy($copy)->items()->firstOrFail();
        $item->update(['status' => OrderStatus::Delivered, 'delivered_at' => now()]);

        $mock = $this->mock(TransferPayoutService::class);
        $mock->shouldReceive('transferToSeller')->once()->andReturn(Transfer::constructFrom(['id' => 'tr_sup']));
        $mock->shouldReceive('transferResellerMargin')->once()
            ->withArgs(fn ($i, $acct) => $i->id === $item->id && $acct === $this->reseller->sellerProfile->stripe_account_id)
            ->andReturn(Transfer::constructFrom(['id' => 'tr_res']));

        Bus::fake([ReleaseResellerMargin::class]);
        (new ReleaseSellerPayout($item->id))->handle(app(TransferPayoutService::class), app(WalletService::class), app(LoyaltyService::class));
        Bus::assertDispatched(ReleaseResellerMargin::class);

        (new ReleaseResellerMargin($item->id))->handle(app(TransferPayoutService::class));
        (new ReleaseResellerMargin($item->id))->handle(app(TransferPayoutService::class));   // never twice

        $item->refresh();
        $this->assertNotNull($item->reseller_paid_at);
        $this->assertSame('tr_res', $item->reseller_transfer_id);
    }

    public function test_a_refund_takes_back_both_the_supplier_payout_and_the_reseller_margin(): void
    {
        $copy = $this->connectAndAdd();
        $item = $this->buy($copy)->items()->firstOrFail();
        $item->update(['status' => OrderStatus::PayoutReleased, 'payout_released_at' => now(), 'reseller_paid_at' => now(), 'reseller_transfer_id' => 'tr_res']);
        $item->order->update(['status' => OrderStatus::Paid]);

        $rev = $this->mock(PayoutReversalService::class);
        $rev->shouldReceive('reverseTransfer')->once();
        $rev->shouldReceive('reverseResellerMargin')->once();
        $this->mock(RefundService::class)->shouldReceive('refundAmount')->once();

        app(BuyerRefundService::class)->refundLine($item->fresh(), 'return', 'Refund');
    }

    public function test_ledgers_show_the_margin_on_both_sides(): void
    {
        $copy = $this->connectAndAdd();
        $item = $this->buy($copy)->items()->firstOrFail();
        $item->update(['status' => OrderStatus::Paid]);

        $ledger = app(SellerLedgerService::class);
        $sup = $ledger->pnl($this->supplier->id, now()->subDay(), now()->addDay());
        $res = $ledger->pnl($this->reseller->id, now()->subDay(), now()->addDay());

        $this->assertEquals(40, $sup['reseller_margins']);
        $this->assertEquals((float) $item->seller_payout_amount, $sup['net_earnings']);
        $this->assertEquals(40, $res['resale_earnings']);
        $this->assertEquals(40, $res['net_earnings']);
    }

    // ---------------------------------------------------------------- portal

    public function test_the_supply_network_page_works_for_both_sides(): void
    {
        $copy = $this->connectAndAdd();

        $this->actingAs($this->reseller)->get(route('seller.supply.index'))->assertOk()->assertSee('Supply Network')->assertSee('AutoPro Warehouse');
        foreach (['listings', 'sales', 'offer', 'resellers'] as $tab) {
            $this->actingAs($this->reseller)->get(route('seller.supply.index', ['tab' => $tab]))->assertOk();
            $this->actingAs($this->supplier)->get(route('seller.supply.index', ['tab' => $tab]))->assertOk();
        }
        $this->actingAs($this->reseller)->get(route('seller.supply.index', ['tab' => 'listings']))->assertSee('Civic LED Tail Lights');
        $this->actingAs($this->supplier)->get(route('seller.supply.index', ['tab' => 'resellers']))->assertSee('Hirev Sports');

        // A resold listing isn't edited in the product form, and isn't in the own-products list.
        $this->actingAs($this->reseller)->get(route('seller.products.edit', $copy))->assertRedirect(route('seller.supply.index', ['tab' => 'listings']));
    }

    public function test_reseller_can_request_add_pause_and_remove_from_the_page(): void
    {
        Livewire::actingAs($this->reseller)->test(SupplyNetwork::class)
            ->call('openSupplier', $this->supplier->id)
            ->call('requestConnection', $this->supplier->id);
        $this->supply()->approve(SupplyConnection::firstOrFail());

        $page = Livewire::actingAs($this->reseller)->test(SupplyNetwork::class, ['supplier' => $this->supplier->id])
            ->call('add', $this->part->id);
        $copy = Product::where('source_product_id', $this->part->id)->firstOrFail();

        $page->set('tab', 'listings')->call('toggleListing', $copy->id);
        $this->assertSame('archived', $copy->fresh()->status);
        $page->call('removeListing', $copy->id);
        $this->assertSoftDeleted($copy);
    }

    public function test_admin_sees_the_network_and_can_end_a_partnership(): void
    {
        $this->seed(\Database\Seeders\AdminPermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $copy = $this->connectAndAdd();
        $this->buy($copy);
        $admin = $this->withTwoFactor(User::factory()->create());
        $admin->assignRole('super_admin');
        $conn = SupplyConnection::firstOrFail();

        $this->actingAs($admin)->get(route('admin.supply.index'))->assertOk()
            ->assertSee('AutoPro Warehouse')->assertSee('Hirev Sports')->assertSee('Civic LED Tail Lights');
        $this->actingAs($admin)->post(route('admin.supply.end', $conn), ['reason' => 'Policy breach'])->assertRedirect();
        $this->assertSame('ended', $conn->fresh()->status);
        $this->assertFalse($copy->fresh()->isListed());
    }

    public function test_supplier_settings_and_exclusions(): void
    {
        Livewire::actingAs($this->supplier)->test(SupplyNetwork::class, ['tab' => 'offer'])
            ->set('margin', 5)->call('saveSettings')->assertHasErrors('margin')
            ->set('margin', 30)->set('autoApprove', true)->call('saveSettings')->assertHasNoErrors()
            ->call('toggleExcluded', $this->part->id);

        $this->assertSame(30.0, (float) $this->supplier->sellerProfile->fresh()->supply_margin_pct);
        $this->assertTrue($this->part->fresh()->supply_excluded);
        $this->assertSame(0, $this->supply()->offered($this->supplier->id)->count());
    }
}
