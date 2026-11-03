<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Livewire\Seller\Campaigns;
use App\Livewire\Seller\Marketing;
use App\Livewire\Seller\Promotions;
use App\Livewire\Storefront\MarketingPreferences;
use App\Mail\CampaignMail;
use App\Models\Affiliate;
use App\Models\AffiliateConversion;
use App\Models\AffiliateProgram;
use App\Models\AutomationSend;
use App\Models\Boost;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\MarketingAutomation;
use App\Models\MarketingCampaign;
use App\Models\MarketingEvent;
use App\Models\MarketingUnsubscribe;
use App\Models\Order;
use App\Models\Product;
use App\Models\SaleEvent;
use App\Models\SellerBlockedBuyer;
use App\Models\SellerProfile;
use App\Models\SellerTeamMember;
use App\Models\User;
use App\Services\Marketing\AffiliateService;
use App\Services\Marketing\CampaignSender;
use App\Services\Marketing\MarketingAnalyticsService;
use App\Services\Marketing\MarketingAutomationService;
use App\Services\Marketing\MarketingConsent;
use App\Services\Marketing\SaleEventService;
use App\Services\Payments\CheckoutPaymentService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Stripe\PaymentIntent;
use Tests\TestCase;

class MarketingSectionTest extends TestCase
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
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'Torque '.Str::random(4), 'business_type' => 'llc', 'status' => 'approved', 'slug' => 's-'.uniqid()]);

        return $this->withTwoFactor($u);
    }

    private function product(User $seller, float $price = 100, array $extra = []): Product
    {
        return Product::create(array_merge([
            'seller_id' => $seller->id,
            'category_id' => Category::create(['name' => 'C', 'slug' => 'c-'.Str::random(6)])->id,
            'title' => 'Rotor '.Str::random(4), 'slug' => 'p-'.Str::random(8),
            'price' => $price, 'quantity' => 10, 'status' => 'active',
        ], $extra))->fresh();
    }

    /** A buyer with a delivered order from $seller. */
    private function customer(User $seller, float $value = 100, ?User $buyer = null, array $item = []): User
    {
        $buyer ??= User::factory()->create();
        $product = $this->product($seller, $value);
        $order = Order::create([
            'order_number' => 'PM-'.strtoupper(Str::random(10)), 'buyer_id' => $buyer->id, 'status' => OrderStatus::Delivered,
            'subtotal' => $value, 'shipping_total' => 0, 'discount_total' => 0, 'credits_used' => 0, 'total' => $value,
            'shipping_address' => ['line1' => 'x'], 'billing_address' => ['line1' => 'x'], 'placed_at' => now(),
        ]);
        $order->items()->create(array_merge([
            'seller_id' => $seller->id, 'product_id' => $product->id, 'product_title' => $product->title,
            'quantity' => 1, 'unit_price' => $value, 'line_total' => $value, 'commission_rate' => 10,
            'platform_fee_amount' => $value / 10, 'seller_payout_amount' => $value * 0.9, 'status' => OrderStatus::Delivered,
            'delivered_at' => now(),
        ], $item));

        return $buyer;
    }

    private function campaign(User $seller, array $extra = []): MarketingCampaign
    {
        return MarketingCampaign::create(array_merge([
            'seller_id' => $seller->id, 'channel' => 'email', 'name' => 'Restock', 'subject' => 'Back in stock',
            'body' => 'Hi {{name}}, shop at {{shop_url}}', 'audience' => ['type' => 'all_customers'], 'status' => 'draft',
        ], $extra));
    }

    // ================= permissions =================

    public function test_staff_without_marketing_permission_is_kept_out(): void
    {
        $seller = $this->seller();
        $staff = $this->withTwoFactor(User::factory()->create(['email' => 'packer@example.com']));
        SellerTeamMember::create([
            'owner_id' => $seller->id, 'user_id' => $staff->id, 'email' => 'packer@example.com',
            'role' => 'viewer', 'abilities' => ['orders.view'], 'status' => 'active',
        ]);

        foreach (['seller.campaigns.index', 'seller.promotions.index', 'seller.social.index', 'seller.automations.index',
            'seller.marketing.index', 'seller.marketing.analytics', 'seller.affiliates.index', 'seller.ads.index', 'seller.storefront.edit'] as $route) {
            $this->actingAs($staff)->get(route($route))->assertForbidden();
        }
        $this->actingAs($seller)->get(route('seller.campaigns.index'))->assertOk();
    }

    // ================= consent & unsubscribe =================

    public function test_campaign_email_has_unsubscribe_link_headers_and_postal_address(): void
    {
        Mail::fake();
        $seller = $this->seller();
        $buyer = $this->customer($seller);

        app(CampaignSender::class)->send($this->campaign($seller));

        Mail::assertSent(CampaignMail::class, function (CampaignMail $mail) use ($buyer) {
            $html = $mail->render();
            $headers = $mail->headers()->text;

            return $mail->hasTo($buyer->email)
                && str_contains($html, 'Unsubscribe from')
                && str_contains($html, config('marketplace.marketing.postal_address'))
                && str_contains($headers['List-Unsubscribe'], '/m/unsubscribe/')
                && $headers['List-Unsubscribe-Post'] === 'List-Unsubscribe=One-Click';
        });
    }

    public function test_unsubscribe_link_confirms_on_get_and_unsubscribes_on_post(): void
    {
        $seller = $this->seller();
        $buyer = $this->customer($seller);
        $url = app(MarketingConsent::class)->unsubscribeUrl($buyer, $seller->id);

        // Link scanners prefetch GETs — that must not unsubscribe anyone.
        $this->get($url)->assertOk()->assertSee('Unsubscribe from');
        $this->assertSame(0, MarketingUnsubscribe::count());

        $this->post($url, ['scope' => 'store'])->assertOk()->assertSee("You're unsubscribed", false);
        $this->assertDatabaseHas('marketing_unsubscribes', ['user_id' => $buyer->id, 'seller_id' => $seller->id]);

        // Tampered link is refused.
        $victim = User::factory()->create();
        $this->post(str_replace('/'.$buyer->id.'/', '/'.$victim->id.'/', $url))->assertForbidden();
        $this->assertDatabaseMissing('marketing_unsubscribes', ['user_id' => $victim->id]);

        // RFC 8058 one-click (mail client POST, no session) → 204, unsubscribes from all.
        $other = $this->customer($seller);
        $this->post(app(MarketingConsent::class)->unsubscribeUrl($other, null), ['List-Unsubscribe' => 'One-Click'])->assertNoContent();
        $this->assertDatabaseHas('marketing_unsubscribes', ['user_id' => $other->id, 'seller_id' => null]);
    }

    public function test_opted_out_and_blocked_buyers_never_get_campaigns(): void
    {
        Mail::fake();
        $seller = $this->seller();
        $keep = $this->customer($seller);
        $optedOut = $this->customer($seller);
        $globallyOut = $this->customer($seller);
        $blocked = $this->customer($seller);
        MarketingUnsubscribe::create(['user_id' => $optedOut->id, 'seller_id' => $seller->id]);
        MarketingUnsubscribe::create(['user_id' => $globallyOut->id, 'seller_id' => null]);
        SellerBlockedBuyer::create(['seller_id' => $seller->id, 'buyer_id' => $blocked->id]);

        $this->assertSame(1, app(CampaignSender::class)->reachable($seller->id, 'all_customers')->count());
        app(CampaignSender::class)->send($c = $this->campaign($seller));

        Mail::assertSentCount(1);
        Mail::assertSent(CampaignMail::class, fn ($m) => $m->hasTo($keep->email));
        $this->assertSame(1, $c->fresh()->stats['recipients']);
    }

    public function test_campaign_stats_count_clicks_without_requiring_opens(): void
    {
        Mail::fake();
        $seller = $this->seller();
        $this->customer($seller);
        $this->customer($seller);
        app(CampaignSender::class)->send($c = $this->campaign($seller));

        // One recipient clicked without the open pixel loading (images off) — still a click.
        $c->recipients()->first()->update(['clicked_at' => now()]);
        app(CampaignSender::class)->refreshStats($c->fresh());

        $stats = $c->fresh()->stats;
        $this->assertSame(0, $stats['opened']);
        $this->assertSame(1, $stats['clicked']);
    }

    public function test_sent_campaigns_cannot_be_edited_and_weekly_cap_applies(): void
    {
        Mail::fake();
        config()->set('marketplace.marketing.campaigns_per_week', 2);
        $seller = $this->seller();
        $this->customer($seller);

        $sent = $this->campaign($seller, ['status' => 'sent', 'sent_at' => now()]);
        $this->expectsEditBlocked($seller, $sent);

        Livewire::actingAs($seller)->test(Campaigns::class)->call('duplicate', $sent->id)
            ->assertSet('name', 'Restock (copy)')->assertSet('showForm', true);

        $this->campaign($seller, ['status' => 'sent', 'sent_at' => now()]);
        $this->assertTrue(app(CampaignSender::class)->weeklyLimitReached($seller->id));

        Livewire::actingAs($seller)->test(Campaigns::class)->call('newCampaign')
            ->set('name', 'Third')->set('subject', 'S')->set('body', 'B')
            ->call('save', true)->assertHasErrors('name');
        $this->assertSame('draft', MarketingCampaign::where('name', 'Third')->value('status') ?? 'draft');
        Mail::assertNothingSent();
    }

    private function expectsEditBlocked(User $seller, MarketingCampaign $c): void
    {
        Livewire::actingAs($seller)->test(Campaigns::class)->call('edit', $c->id)->assertStatus(422);
        $this->assertSame('sent', $c->fresh()->status);
    }

    // ================= automations =================

    public function test_run_now_only_runs_this_sellers_automations_and_respects_cap_and_opt_out(): void
    {
        Mail::fake();
        $mine = $this->seller();
        $theirs = $this->seller();
        $buyer = $this->customer($mine, 100, null, ['created_at' => now()->subDays(2)]);
        $this->customer($theirs, 100, null, ['created_at' => now()->subDays(2)]);
        foreach ([$mine, $theirs] as $s) {
            MarketingAutomation::create(['seller_id' => $s->id, 'trigger' => 'post_purchase', 'channel' => 'email', 'is_active' => true, 'delay_hours' => 1, 'subject' => 'Thanks', 'body' => 'Thanks {{name}}']);
        }
        Order::query()->update(['created_at' => now()->subDays(2)]);

        // Frequency cap: this store already emailed the buyer today.
        app(CampaignSender::class)->send($this->campaign($mine));
        $this->assertSame(0, app(MarketingAutomationService::class)->run($mine->id));

        $this->travel(25)->hours();
        $this->assertSame(1, app(MarketingAutomationService::class)->run($mine->id));
        $this->assertSame(0, AutomationSend::whereHas('automation', fn ($q) => $q->where('seller_id', $theirs->id))->count(), 'other store untouched');

        // Opted out → nothing more.
        MarketingUnsubscribe::create(['user_id' => $buyer->id, 'seller_id' => $mine->id]);
        AutomationSend::query()->delete();
        $this->travel(25)->hours();
        $this->assertSame(0, app(MarketingAutomationService::class)->run($mine->id));
    }

    // ================= attribution & insights =================

    public function test_ad_conversion_only_credits_the_advertised_sellers_items(): void
    {
        Notification::fake();
        $advertiser = $this->seller();
        $other = $this->seller();
        $adProduct = $this->product($advertiser, 40);
        $otherProduct = $this->product($other, 300);
        $boost = Boost::create(['product_id' => $adProduct->id, 'seller_id' => $advertiser->id, 'placement' => 'search', 'days' => 7, 'fee' => 7, 'starts_at' => now(), 'ends_at' => now()->addDays(7), 'status' => 'active']);
        $buyer = User::factory()->create();

        $this->mock(CheckoutPaymentService::class)->shouldReceive('createPaymentIntent')
            ->andReturn(PaymentIntent::constructFrom(['id' => 'pi_'.Str::random(6), 'client_secret' => 'x']));
        $cart = Cart::firstOrCreate(['user_id' => $buyer->id]);
        $cart->items()->create(['product_id' => $adProduct->id, 'quantity' => 1]);
        $cart->items()->create(['product_id' => $otherProduct->id, 'quantity' => 1]);

        $this->actingAs($buyer)->withCookie('pm_ad', 'boost:'.$boost->id)->post(route('checkout.store'), [
            'name' => 'B', 'line1' => '1 St', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country' => 'US',
        ])->assertSessionHasNoErrors();

        // Credited $40 (their item), not $340 + shipping.
        $this->assertEquals(40.0, (float) $boost->fresh()->attributed_revenue);
        $this->assertDatabaseHas('marketing_events', ['seller_id' => $advertiser->id, 'channel' => 'ads', 'kind' => 'conversion', 'revenue' => 40]);
        $this->assertDatabaseMissing('marketing_events', ['seller_id' => $other->id, 'channel' => 'ads']);
    }

    public function test_insights_respect_the_date_window_and_show_cost_and_return(): void
    {
        $seller = $this->seller();
        \App\Models\AdTransaction::create(['seller_id' => $seller->id, 'type' => 'boost_spend', 'amount' => -20, 'balance_after' => 0, 'description' => 'x']);
        $order = Order::create([
            'order_number' => 'PM-X1', 'buyer_id' => User::factory()->create()->id, 'status' => OrderStatus::Delivered,
            'subtotal' => 80, 'total' => 80, 'shipping_address' => ['line1' => 'x'], 'billing_address' => ['line1' => 'x'],
        ]);
        MarketingEvent::click($seller->id, 'ads', 1);
        MarketingEvent::conversion($seller->id, 'ads', 1, $order->id, 80);
        MarketingEvent::create(['seller_id' => $seller->id, 'channel' => 'ads', 'kind' => 'conversion', 'ref_id' => 1, 'revenue' => 500, 'created_at' => now()->subDays(45)]);

        $rows = collect(app(MarketingAnalyticsService::class)->trafficBySource($seller->id, now()->subDays(6)->startOfDay(), now()))->keyBy('source');
        $this->assertEquals(80.0, $rows['ads']['revenue']); // the 45-day-old $500 is outside a 7-day window
        $this->assertEquals(20.0, $rows['ads']['cost']);
        $this->assertEquals(4.0, $rows['ads']['roas']);

        $this->actingAs($seller)->get(route('seller.marketing.analytics', ['days' => 7]))->assertOk()->assertSee('4×', false);
    }

    // ================= sales =================

    public function test_sale_event_marks_down_restores_and_skips_listings_with_their_own_sale(): void
    {
        $seller = $this->seller();
        $a = $this->product($seller, 100);
        $b = $this->product($seller, 50);
        $own = $this->product($seller, 80, ['sale_price' => 70]);

        $result = app(SaleEventService::class)->create($seller, 'Brake Week', 20, now(), now()->addDays(3), [$a->id, $b->id, $own->id]);

        $this->assertCount(1, $result['skipped']);
        $this->assertEquals(80.0, $a->fresh()->effectivePrice());
        $this->assertEquals(40.0, $b->fresh()->effectivePrice());
        $this->assertTrue($a->fresh()->isOnDeal());
        $this->assertEquals(70.0, (float) $own->fresh()->sale_price); // untouched

        // Ends on schedule → listings go back.
        $this->travel(4)->days();
        app(SaleEventService::class)->finishEnded();
        $this->assertNull($a->fresh()->sale_price);
        $this->assertEquals(100.0, $a->fresh()->effectivePrice());
        $this->assertSame('ended', $result['event']->fresh()->status);
    }

    public function test_cancel_sale_from_promotions_page_restores_prices(): void
    {
        $seller = $this->seller();
        $p = $this->product($seller, 100);

        Livewire::actingAs($seller)->test(Promotions::class)
            ->call('newSale')->set('saleName', 'Flash')->set('salePercent', 25)
            ->set('saleProducts', [(string) $p->id])
            ->call('createSale')->assertHasNoErrors();
        $this->assertEquals(75.0, $p->fresh()->effectivePrice());

        $event = SaleEvent::firstOrFail();
        Livewire::actingAs($seller)->test(Promotions::class)->call('cancelSale', $event->id);
        $this->assertEquals(100.0, $p->fresh()->effectivePrice());
        $this->assertSame('cancelled', $event->fresh()->status);
    }

    public function test_private_codes_cannot_be_edited_from_promotions(): void
    {
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $code = Coupon::create(['code' => 'FORX', 'type' => 'fixed', 'value' => 10, 'seller_id' => $seller->id, 'user_id' => $buyer->id, 'is_active' => true]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::actingAs($seller)->test(Promotions::class)->call('edit', $code->id);
    }

    // ================= affiliates =================

    public function test_affiliate_commission_is_withheld_from_payout_and_reversed_on_refund(): void
    {
        $seller = $this->seller();
        $program = AffiliateProgram::create(['seller_id' => $seller->id, 'is_active' => true, 'commission_pct' => 10, 'auto_approve' => true]);
        $affiliate = app(AffiliateService::class)->join($program, 'P', 'p@example.com', null, User::factory()->create());
        $buyer = $this->customer($seller, 200);
        $order = Order::where('buyer_id', $buyer->id)->firstOrFail();
        $line = $order->items()->first();

        $conversion = app(AffiliateService::class)->attribute($order, $affiliate->code);
        $this->assertEquals(160.0, (float) $line->fresh()->seller_payout_amount); // 180 − 20 commission

        $line->update(['status' => OrderStatus::Refunded]);
        app(AffiliateService::class)->approveDelivered();

        $this->assertSame('reversed', $conversion->fresh()->status);
        $this->assertEquals(0.0, (float) $affiliate->fresh()->earnings);
        $this->assertSame(0, $affiliate->fresh()->payableBalance() > 0 ? 1 : 0);
    }

    // ================= overview & buyer side =================

    public function test_announcement_only_pings_followers_once_per_day(): void
    {
        Notification::fake();
        $seller = $this->seller();
        $fan = User::factory()->create();
        $fan->followedSellers()->attach($seller->id);

        $c = Livewire::actingAs($seller)->test(Marketing::class)->set('announcement', 'Free shipping this week')->call('publishAnnouncement');
        $c->set('announcement', 'Free shipping this week!')->call('publishAnnouncement');

        Notification::assertSentToTimes($fan, \App\Notifications\SellerAnnouncement::class, 1);
        $this->assertSame('Free shipping this week!', $seller->sellerProfile->fresh()->announcement);
    }

    public function test_buyer_marketing_preferences(): void
    {
        $seller = $this->seller();
        $buyer = User::factory()->create();
        MarketingUnsubscribe::create(['user_id' => $buyer->id, 'seller_id' => $seller->id]);

        $c = Livewire::actingAs($buyer)->test(MarketingPreferences::class)->assertSee($seller->sellerProfile->business_name);
        $c->call('resubscribe', $seller->id);
        $this->assertSame(0, MarketingUnsubscribe::count());

        $c->call('toggleAll');
        $this->assertDatabaseHas('marketing_unsubscribes', ['user_id' => $buyer->id, 'seller_id' => null]);

        $c->set('phone', '')->call('toggleSms')->assertHasErrors('phone');
        $c->set('phone', '+1 555 123 4567')->call('toggleSms')->assertHasNoErrors();
        $this->assertNotNull($buyer->fresh()->sms_opt_in_at);

        $this->actingAs($buyer)->get(route('marketing.preferences'))->assertOk();
    }

    public function test_marketing_pages_render(): void
    {
        $seller = $this->seller();
        $this->customer($seller);

        foreach (['seller.promotions.index', 'seller.campaigns.index', 'seller.automations.index', 'seller.social.index',
            'seller.marketing.index', 'seller.marketing.analytics', 'seller.affiliates.index'] as $route) {
            $this->actingAs($seller)->get(route($route))->assertOk();
        }
        $this->actingAs($seller)->get(route('seller.promotions.index', ['tab' => 'private']))->assertOk();
        $this->actingAs($seller)->get(route('seller.social.index', ['tab' => 'posts']))->assertOk();
    }
}
