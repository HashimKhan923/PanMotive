<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Livewire\Seller\Automations;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\MarketingAutomation;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoLink;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Marketing\MarketingAnalyticsService;
use App\Services\Marketing\MarketingAutomationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MarketingAnalyticsAutomationsTest extends TestCase
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
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'M5 '.uniqid(), 'business_type' => 'llc', 'status' => 'approved', 'slug' => 'm5-'.uniqid()]);

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

    private function order(User $seller, User $buyer, float $value, OrderStatus $status, ?\Carbon\Carbon $at = null): Order
    {
        $order = Order::create([
            'order_number' => 'PM-'.strtoupper(uniqid()), 'buyer_id' => $buyer->id,
            'subtotal' => $value, 'total' => $value,
            'shipping_address' => ['line1' => 'x'], 'billing_address' => ['line1' => 'x'],
            'status' => $status,
        ]);
        $order->items()->create([
            'seller_id' => $seller->id, 'product_id' => $this->product($seller)->id,
            'product_title' => 'P', 'quantity' => 1, 'unit_price' => $value, 'line_total' => $value,
            'commission_rate' => 10, 'platform_fee_amount' => 10, 'seller_payout_amount' => $value - 10,
            'status' => $status,
        ]);
        if ($at) {
            $order->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
            $order->items()->update(['created_at' => $at, 'updated_at' => $at]);
        }

        return $order;
    }

    /* ---------------- analytics ---------------- */

    public function test_traffic_by_source_aggregates_channels_and_organic_remainder(): void
    {
        $seller = $this->seller();
        $buyer = User::factory()->create();

        $link = PromoLink::create(['seller_id' => $seller->id, 'code' => 'ABCD1234']);
        $fromLink = $this->order($seller, $buyer, 100, OrderStatus::Delivered);
        $this->order($seller, $buyer, 250, OrderStatus::Delivered); // nobody can claim this one → organic

        foreach (range(1, 3) as $i) {
            \App\Models\MarketingEvent::click($seller->id, 'promo_link', $link->id);
        }
        \App\Models\MarketingEvent::conversion($seller->id, 'promo_link', $link->id, $fromLink->id, 100);
        // An old click outside the window must not count.
        \App\Models\MarketingEvent::create(['seller_id' => $seller->id, 'channel' => 'promo_link', 'kind' => 'click', 'ref_id' => $link->id, 'created_at' => now()->subDays(60)]);

        $rows = app(MarketingAnalyticsService::class)->trafficBySource($seller->id, now()->subDays(29)->startOfDay(), now());
        $bySource = collect($rows)->keyBy('source');

        $this->assertSame(3, $bySource['promo_link']['clicks']);
        $this->assertSame(1, $bySource['promo_link']['orders']);
        $this->assertEquals(100.0, $bySource['promo_link']['revenue']);
        $this->assertSame(1, $bySource['organic']['orders']);
        $this->assertEquals(250.0, $bySource['organic']['revenue']);
    }

    public function test_customer_ltv_and_cohorts(): void
    {
        $seller = $this->seller();
        $repeat = User::factory()->create(['name' => 'Repeat Rita']);
        $once = User::factory()->create(['name' => 'One-time Otto']);

        $this->order($seller, $repeat, 100, OrderStatus::Delivered, now()->subMonths(3));
        $this->order($seller, $repeat, 150, OrderStatus::Delivered, now()->subMonths(1));
        $this->order($seller, $once, 80, OrderStatus::Delivered, now()->subMonths(2));

        $ltv = app(MarketingAnalyticsService::class)->customerLtv($seller->id);
        $this->assertSame(2, $ltv['customers']);
        $this->assertEquals(165.0, $ltv['avg_ltv']); // (250 + 80) / 2
        $this->assertEquals(50.0, $ltv['repeat_rate']);
        $this->assertSame('Repeat Rita', $ltv['top'][0]['name']);

        $cohorts = app(MarketingAnalyticsService::class)->cohortRetention($seller->id);
        $this->assertNotEmpty($cohorts);
    }

    public function test_marketing_insights_page_renders(): void
    {
        $seller = $this->seller();

        $this->actingAs($seller)->get(route('seller.marketing.analytics'))
            ->assertOk()
            ->assertSee('Revenue by channel')
            ->assertSee('Cohort retention');
    }

    /* ---------------- automations component ---------------- */

    public function test_automations_component_edits_and_toggles(): void
    {
        $seller = $this->seller();

        Livewire::actingAs($seller)->test(Automations::class)
            ->call('edit', 'abandoned_cart')
            ->set('isActive', true)
            ->set('delayHours', 6)
            ->set('subject', 'Still shopping?')
            ->set('body', 'Hi {{name}}, come back to {{store}}: {{shop_url}}')
            ->call('save')
            ->assertHasNoErrors();

        $a = MarketingAutomation::where('seller_id', $seller->id)->where('trigger', 'abandoned_cart')->firstOrFail();
        $this->assertTrue($a->is_active);
        $this->assertSame(6, $a->delay_hours);

        Livewire::actingAs($seller)->test(Automations::class)->call('toggle', 'abandoned_cart');
        $this->assertFalse($a->fresh()->is_active);
    }

    /* ---------------- automation engine ---------------- */

    public function test_post_purchase_automation_sends_once(): void
    {
        Mail::fake();
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $this->order($seller, $buyer, 120, OrderStatus::Delivered, now()->subHours(48));

        MarketingAutomation::create([
            'seller_id' => $seller->id, 'trigger' => 'post_purchase', 'is_active' => true,
            'channel' => 'email', 'delay_hours' => 24,
            'subject' => 'Thanks {{name}}', 'body' => 'From {{store}} — {{shop_url}}',
        ]);

        $sent = app(MarketingAutomationService::class)->run();
        $this->assertSame(1, $sent);
        Mail::assertSent(\App\Mail\CampaignMail::class, 1);

        // running again must not re-send
        $this->assertSame(0, app(MarketingAutomationService::class)->run());
    }

    public function test_abandoned_cart_automation_sends_for_cold_cart(): void
    {
        Mail::fake();
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $product = $this->product($seller);

        $cart = Cart::create(['user_id' => $buyer->id]);
        $item = CartItem::create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1]);
        $item->forceFill(['updated_at' => now()->subHours(10)])->save();

        MarketingAutomation::create([
            'seller_id' => $seller->id, 'trigger' => 'abandoned_cart', 'is_active' => true,
            'channel' => 'email', 'delay_hours' => 4,
            'subject' => 'Your cart', 'body' => 'Hi {{name}} {{shop_url}}',
        ]);

        $this->assertSame(1, app(MarketingAutomationService::class)->run());
    }

    public function test_win_back_and_new_follower_and_price_drop(): void
    {
        Mail::fake();
        $seller = $this->seller();

        // win-back: last order 200 days ago
        $lapsed = User::factory()->create();
        $this->order($seller, $lapsed, 90, OrderStatus::Delivered, now()->subDays(200));
        MarketingAutomation::create([
            'seller_id' => $seller->id, 'trigger' => 'win_back', 'is_active' => true, 'channel' => 'email',
            'delay_hours' => 1, 'subject' => 'Miss you {{name}}', 'body' => '{{store}} {{shop_url}}',
            'settings' => ['lapsed_days' => 90],
        ]);

        // new follower: followed 3h ago
        $follower = User::factory()->create();
        DB::table('seller_followers')->insert([
            'user_id' => $follower->id, 'seller_id' => $seller->id,
            'created_at' => now()->subHours(3), 'updated_at' => now()->subHours(3),
        ]);
        MarketingAutomation::create([
            'seller_id' => $seller->id, 'trigger' => 'new_follower', 'is_active' => true, 'channel' => 'email',
            'delay_hours' => 1, 'subject' => 'Welcome {{name}}', 'body' => '{{store}} {{shop_url}}',
        ]);

        // price drop: wishlist item saved before a now-live sale
        $wisher = User::factory()->create();
        $onSale = $this->product($seller);
        $onSale->update(['sale_price' => 60, 'sale_starts_at' => now()->subHour(), 'deal_ends_at' => now()->addDays(3)]);
        $wl = Wishlist::create(['user_id' => $wisher->id]);
        $wi = WishlistItem::create(['wishlist_id' => $wl->id, 'product_id' => $onSale->id]);
        $wi->forceFill(['created_at' => now()->subDays(5)])->save();
        MarketingAutomation::create([
            'seller_id' => $seller->id, 'trigger' => 'price_drop', 'is_active' => true, 'channel' => 'email',
            'delay_hours' => 1, 'subject' => 'Price drop', 'body' => 'Hi {{name}} {{shop_url}}',
        ]);

        $sent = app(MarketingAutomationService::class)->run();
        $this->assertSame(3, $sent);
    }

    public function test_automation_open_click_and_conversion_attribution(): void
    {
        Mail::fake();
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $this->order($seller, $buyer, 100, OrderStatus::Delivered, now()->subHours(48));

        $automation = MarketingAutomation::create([
            'seller_id' => $seller->id, 'trigger' => 'post_purchase', 'is_active' => true, 'channel' => 'email',
            'delay_hours' => 24, 'subject' => 'Thanks', 'body' => 'Hi {{name}} {{shop_url}}',
        ]);
        app(MarketingAutomationService::class)->run();

        $send = $automation->sends()->firstOrFail();

        $this->get(route('am.open', $send->token))->assertOk()->assertHeader('content-type', 'image/gif');
        $this->assertNotNull($send->fresh()->opened_at);

        $this->get(route('am.click', $send->token))
            ->assertRedirect(route('sellers.show', $seller->sellerProfile))
            ->assertCookie('pm_automation', $send->token);
        $this->assertNotNull($send->fresh()->clicked_at);

        // a later purchase from that seller attributes back
        $product = Product::where('seller_id', $seller->id)->first();
        $this->actingAs($buyer)->withCookie('pm_automation', $send->token)
            ->post(route('cart.add'), ['product_id' => $product->id, 'quantity' => 1]);
        $this->actingAs($buyer)->withCookie('pm_automation', $send->token)
            ->post(route('checkout.store'), [
                'name' => 'B', 'line1' => '1 A St', 'city' => 'X', 'state' => 'CA', 'postal_code' => '90001', 'country' => 'US',
            ]);

        $this->assertNotNull($send->fresh()->converted_order_id);
        $this->assertGreaterThan(0, (float) $send->fresh()->conversion_value);
    }
}
