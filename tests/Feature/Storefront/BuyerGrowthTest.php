<?php

namespace Tests\Feature\Storefront;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Notifications\PriceDropAlert;
use App\Services\Buyer\WishlistAlertService;
use App\Services\Membership\MembershipService;
use App\Services\Shipping\ShippingEstimateService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BuyerGrowthTest extends TestCase
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
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'BG '.uniqid(), 'business_type' => 'llc', 'status' => 'approved', 'slug' => 'bg-'.uniqid()]);

        return $u;
    }

    private function product(User $seller, float $price = 40, int $qty = 25): Product
    {
        return Product::create([
            'seller_id' => $seller->id,
            'category_id' => Category::create(['name' => 'C'.uniqid(), 'slug' => 'c-'.uniqid()])->id,
            'title' => 'Filter '.uniqid(), 'slug' => 'f-'.uniqid(),
            'price' => $price, 'quantity' => $qty, 'status' => 'active', 'weight_lbs' => 3,
        ]);
    }

    /* ---------------- PanMotive Plus ---------------- */

    public function test_join_plus_debits_wallet_and_sets_expiry(): void
    {
        $user = User::factory()->create(['wallet_balance' => 100]);
        $svc = app(MembershipService::class);

        $until = $svc->join($user);

        $this->assertTrue($svc->isActive($user->fresh()));
        $this->assertEquals(100 - $svc->price(), (float) $user->fresh()->wallet_balance);
        $this->assertTrue($until->isFuture());
    }

    public function test_plus_join_route_rejects_insufficient_credit(): void
    {
        $user = User::factory()->create(['wallet_balance' => 5]);

        $this->actingAs($user)->post(route('plus.join'))
            ->assertRedirect()
            ->assertSessionHasErrors('plus');

        $this->assertFalse($user->fresh()->isPlusMember());
    }

    public function test_plus_member_gets_free_shipping(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller, 20); // heavy-ish, well under any threshold
        $member = User::factory()->create(['plus_until' => now()->addYear()]);
        $guestlike = User::factory()->create();

        $lines = collect([['product' => $product, 'quantity' => 1, 'line_total' => 20.0]]);

        $this->assertSame(0.0, app(ShippingEstimateService::class)->estimate($lines, $member)['shipping']);
        $this->assertGreaterThan(0, app(ShippingEstimateService::class)->estimate($lines, $guestlike)['shipping']);
    }

    public function test_membership_auto_renew_extends_from_current_expiry(): void
    {
        $svc = app(MembershipService::class);
        $user = User::factory()->create(['wallet_balance' => $svc->price() * 3, 'plus_until' => now()->addHours(12), 'plus_auto_renew' => true]);

        $renewed = $svc->renewDue();

        $this->assertSame(1, $renewed);
        // extended a full term from the old expiry (~12 months out, not ~11)
        $this->assertTrue($user->fresh()->plus_until->gt(now()->addMonths(11)));
    }

    /* ---------------- Subscribe & Save ---------------- */

    public function test_store_route_creates_subscription(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller);
        $buyer = User::factory()->create();

        $this->actingAs($buyer)->post(route('subscriptions.store'), [
            'product_id' => $product->id, 'quantity' => 2, 'interval_days' => 60,
        ])->assertRedirect(route('subscriptions.index'));

        $sub = Subscription::where('buyer_id', $buyer->id)->firstOrFail();
        $this->assertSame(60, $sub->interval_days);
        $this->assertSame(2, $sub->quantity);
        $this->assertEquals(5.0, (float) $sub->discount_pct);
        $this->assertTrue($sub->next_run_at->isFuture());
    }

    public function test_run_due_places_discounted_order_and_reschedules(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller, 100);
        $buyer = User::factory()->create(['wallet_balance' => 500]);

        $sub = app(SubscriptionService::class)->create($buyer, $product, [
            'quantity' => 1, 'interval_days' => 30, 'discount_pct' => 5, 'starts_now' => true,
            'shipping_address' => ['line1' => '1 A St', 'city' => 'X', 'state' => 'CA', 'postal_code' => '90001', 'country' => 'US'],
        ]);

        $placed = app(SubscriptionService::class)->runDue();
        $this->assertSame(1, $placed);

        $sub->refresh();
        $this->assertSame(1, $sub->orders_count);
        $this->assertNotNull($sub->last_order_id);
        $this->assertTrue($sub->next_run_at->isFuture());

        $order = $sub->last_order_id ? \App\Models\Order::find($sub->last_order_id) : null;
        $this->assertNotNull($order);
        $this->assertEquals(95.0, (float) $order->subtotal); // 100 - 5%
        $this->assertSame(OrderStatus::Paid, $order->status); // wallet covered it
        $this->assertSame($sub->id, $order->subscription_id);
    }

    public function test_run_due_not_charged_when_wallet_short(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller, 100);
        $buyer = User::factory()->create(['wallet_balance' => 1]);

        app(SubscriptionService::class)->create($buyer, $product, ['interval_days' => 30, 'starts_now' => true]);
        app(SubscriptionService::class)->runDue();

        $order = \App\Models\Order::where('buyer_id', $buyer->id)->firstOrFail();
        $this->assertSame(OrderStatus::Placed, $order->status);
    }

    public function test_subscription_update_actions(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller);
        $buyer = User::factory()->create();
        $sub = app(SubscriptionService::class)->create($buyer, $product, ['interval_days' => 30]);

        $this->actingAs($buyer)->patch(route('subscriptions.update', $sub), ['action' => 'pause'])->assertRedirect();
        $this->assertSame('paused', $sub->fresh()->status);

        $this->actingAs($buyer)->patch(route('subscriptions.update', $sub), ['action' => 'resume'])->assertRedirect();
        $this->assertSame('active', $sub->fresh()->status);

        $before = $sub->fresh()->next_run_at;
        $this->actingAs($buyer)->patch(route('subscriptions.update', $sub), ['action' => 'skip'])->assertRedirect();
        $this->assertTrue($sub->fresh()->next_run_at->gt($before));

        $this->actingAs($buyer)->patch(route('subscriptions.update', $sub), ['action' => 'cancel'])->assertRedirect();
        $this->assertSame('cancelled', $sub->fresh()->status);
    }

    public function test_other_users_cannot_touch_a_subscription(): void
    {
        $seller = $this->seller();
        $sub = app(SubscriptionService::class)->create(User::factory()->create(), $this->product($seller), ['interval_days' => 30]);

        $this->actingAs(User::factory()->create())
            ->patch(route('subscriptions.update', $sub), ['action' => 'cancel'])
            ->assertForbidden();
    }

    /* ---------------- Wishlist price-drop alerts ---------------- */

    public function test_wishlist_toggle_records_price_and_sweep_notifies_on_drop(): void
    {
        Notification::fake();
        $seller = $this->seller();
        $product = $this->product($seller, 100);
        $buyer = User::factory()->create();

        $this->actingAs($buyer)->post(route('wishlist.toggle'), ['product_id' => $product->id]);
        $item = WishlistItem::where('product_id', $product->id)->firstOrFail();
        $this->assertEquals(100.0, (float) $item->price_at_add);

        // small drop — ignored
        $product->update(['price' => 98]);
        $this->assertSame(0, app(WishlistAlertService::class)->sweep());

        // real drop
        $product->update(['price' => 80]);
        $this->assertSame(1, app(WishlistAlertService::class)->sweep());
        Notification::assertSentTo($buyer, PriceDropAlert::class);

        $this->assertNotNull($item->fresh()->price_drop_notified_at);
        // idempotent within the cooldown window
        $this->assertSame(0, app(WishlistAlertService::class)->sweep());
    }
}
