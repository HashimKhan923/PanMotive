<?php

namespace Tests\Feature\Marketing;

use App\Enums\OrderStatus;
use App\Livewire\Seller\OffsiteAds;
use App\Models\Cart;
use App\Models\Category;
use App\Models\OffsiteAdAttribution;
use App\Models\OffsiteAdClick;
use App\Models\OffsiteAdSpend;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Marketing\OffsiteAdsService;
use App\Services\Payments\CheckoutPaymentService;
use App\Services\Seller\SellerLedgerService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Stripe\PaymentIntent;
use Tests\TestCase;

class OffsiteAdsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seller(bool $optOut = false): User
    {
        $u = User::factory()->create();
        $u->assignRole('seller');
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'Shop '.uniqid(), 'business_type' => 'llc',
            'status' => 'approved', 'slug' => 's-'.uniqid(), 'offsite_ads_opt_out' => $optOut]);

        return $this->withTwoFactor($u);
    }

    private function product(User $seller, float $price = 100, bool $image = false): Product
    {
        $p = Product::create([
            'seller_id' => $seller->id,
            'category_id' => Category::create(['name' => 'Brakes', 'slug' => 'b-'.Str::random(6)])->id,
            'title' => 'Brake Pads '.Str::random(4), 'slug' => 'bp-'.Str::random(8),
            'price' => $price, 'quantity' => 10, 'status' => 'active', 'part_number' => 'BP-1',
        ]);
        if ($image) {
            $p->addMedia(UploadedFile::fake()->image('p.jpg'))->toMediaCollection('images');
        }

        return $p->fresh();
    }

    private function cookieFor(User $seller, int $daysAgo = 1, string $channel = 'fb'): string
    {
        return json_encode([['s' => $seller->id, 'c' => 0, 'ch' => $channel, 't' => now()->subDays($daysAgo)->timestamp]]);
    }

    /** Place a real checkout with the given cart lines and Offsite Ads cookie. */
    private function checkout(array $lines, ?string $cookie): Order
    {
        $this->mock(CheckoutPaymentService::class)->shouldReceive('createPaymentIntent')
            ->andReturn(PaymentIntent::constructFrom(['id' => 'pi_'.Str::random(8), 'client_secret' => 'x']));

        $buyer = User::factory()->create();
        $cart = Cart::create(['user_id' => $buyer->id]);
        foreach ($lines as [$product, $qty]) {
            $cart->items()->create(['product_id' => $product->id, 'quantity' => $qty]);
        }

        $req = $this->actingAs($buyer);
        if ($cookie) {
            $req = $req->withCookie(OffsiteAdsService::COOKIE, $cookie);
        }
        $req->post(route('checkout.store'), [
            'name' => 'B', 'line1' => '1 St', 'city' => 'X', 'state' => 'TX', 'postal_code' => '78701', 'country' => 'US',
        ])->assertOk();

        return Order::latest('id')->firstOrFail();
    }

    // ---------------- feed ----------------

    public function test_feed_lists_participating_listings_with_tracked_links(): void
    {
        $in = $this->product($this->seller(), 80, image: true);
        $optedOut = $this->product($this->seller(optOut: true), image: true);
        $noImage = $this->product($this->seller());

        $xml = $this->get(route('feeds.offsite', 'google'))->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();

        $this->assertStringContainsString('<g:id>PM-'.$in->id.'</g:id>', $xml);
        $this->assertStringContainsString('oa=google', $xml);
        $this->assertStringContainsString('<g:price>80.00 USD</g:price>', $xml);
        $this->assertStringNotContainsString('PM-'.$optedOut->id.'<', $xml);
        $this->assertStringNotContainsString('PM-'.$noImage->id.'<', $xml);
        $this->assertNotFalse(simplexml_load_string($xml)); // well-formed
    }

    public function test_feed_rejects_unknown_channel_and_enforces_key(): void
    {
        $this->get(route('feeds.offsite', 'myspace'))->assertNotFound();

        config()->set('marketplace.offsite_ads.feed_key', 'secret123');
        $this->get(route('feeds.offsite', 'fb'))->assertForbidden();
        $this->get(route('feeds.offsite', ['channel' => 'fb', 'key' => 'secret123']))->assertOk();
    }

    // ---------------- click tracking ----------------

    public function test_ad_click_is_recorded_once_and_sets_the_attribution_cookie(): void
    {
        $product = $this->product($this->seller());
        // A signed-in shopper keeps one visitor identity across requests (the test client
        // doesn't carry the guest session cookie between requests the way a browser does).
        $shopper = User::factory()->create();

        $this->actingAs($shopper)->get(route('products.show', $product->slug).'?oa=fb')->assertOk()->assertCookie(OffsiteAdsService::COOKIE);
        $this->actingAs($shopper)->get(route('products.show', $product->slug).'?oa=fb'); // refresh — same visitor, same day

        $this->assertSame(1, OffsiteAdClick::where('product_id', $product->id)->where('channel', 'fb')->count());
    }

    public function test_clicks_for_opted_out_sellers_or_unknown_channels_are_ignored(): void
    {
        $out = $this->product($this->seller(optOut: true));
        $this->get(route('products.show', $out->slug).'?oa=fb')->assertOk()->assertCookieMissing(OffsiteAdsService::COOKIE);

        $in = $this->product($this->seller());
        $this->get(route('products.show', $in->slug).'?oa=hacker')->assertOk()->assertCookieMissing(OffsiteAdsService::COOKIE);

        $this->assertSame(0, OffsiteAdClick::count());
    }

    // ---------------- fee at checkout ----------------

    public function test_order_after_ad_click_charges_fee_from_payout(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller, 100);

        $order = $this->checkout([[$product, 2]], $this->cookieFor($seller));

        $item = $order->items()->firstOrFail();
        $this->assertEquals(30.00, (float) $item->offsite_ad_fee);            // 15% of $200
        $this->assertEquals(200 - 20 - 30, (float) $item->seller_payout_amount); // minus 10% commission and ad fee
        $a = OffsiteAdAttribution::firstOrFail();
        $this->assertEquals(200, (float) $a->attributed_sales);
        $this->assertEquals(15, (float) $a->fee_rate);
        $this->assertSame('fb', $a->channel);
    }

    public function test_only_the_clicked_seller_pays_and_fee_is_split_across_their_lines(): void
    {
        $clicked = $this->seller();
        $other = $this->seller();
        $a = $this->product($clicked, 60);
        $b = $this->product($clicked, 40);
        $c = $this->product($other, 100);

        $order = $this->checkout([[$a, 1], [$b, 1], [$c, 1]], $this->cookieFor($clicked));

        $this->assertEquals(15.00, (float) $order->items()->where('seller_id', $clicked->id)->sum('offsite_ad_fee')); // 15% of $100
        $this->assertEquals(9.00, (float) $order->items()->where('product_id', $a->id)->value('offsite_ad_fee'));
        $this->assertEquals(0, (float) $order->items()->where('seller_id', $other->id)->sum('offsite_ad_fee'));
    }

    public function test_fee_is_capped_and_large_sellers_pay_the_lower_rate(): void
    {
        $small = $this->seller();
        $order = $this->checkout([[$this->product($small, 1000), 1]], $this->cookieFor($small));
        $this->assertEquals(100.00, (float) $order->items()->first()->offsite_ad_fee); // 15% of $1000 = $150 → capped

        $large = $this->seller();
        $history = Order::create(['order_number' => 'PM-H'.Str::random(6), 'buyer_id' => User::factory()->create()->id, 'status' => OrderStatus::Paid,
            'subtotal' => 12000, 'total' => 12000, 'shipping_address' => [], 'billing_address' => []]);
        $history->items()->create(['seller_id' => $large->id, 'product_id' => $this->product($large)->id, 'product_title' => 'x', 'quantity' => 1,
            'unit_price' => 12000, 'line_total' => 12000, 'commission_rate' => 10, 'platform_fee_amount' => 1200, 'seller_payout_amount' => 10800,
            'status' => OrderStatus::Delivered]);

        $order2 = $this->checkout([[$this->product($large, 100), 1]], $this->cookieFor($large));
        $this->assertEquals(12.00, (float) $order2->items()->first()->offsite_ad_fee); // 12%
    }

    public function test_no_fee_without_a_click_or_after_the_window(): void
    {
        $seller = $this->seller();
        $this->assertEquals(0, (float) $this->checkout([[$this->product($seller), 1]], null)->items()->first()->offsite_ad_fee);
        $this->assertEquals(0, (float) $this->checkout([[$this->product($seller), 1]], $this->cookieFor($seller, daysAgo: 31))->items()->first()->offsite_ad_fee);
        $this->assertSame(0, OffsiteAdAttribution::count());
    }

    public function test_attribution_is_idempotent(): void
    {
        $seller = $this->seller();
        $order = $this->checkout([[$this->product($seller, 100), 1]], $this->cookieFor($seller));

        app(OffsiteAdsService::class)->attribute($order, $this->cookieFor($seller));

        $this->assertSame(1, OffsiteAdAttribution::count());
        $this->assertEquals(15, (float) $order->items()->first()->offsite_ad_fee);
    }

    // ---------------- seller controls & reporting ----------------

    public function test_small_seller_can_opt_out_but_large_seller_cannot(): void
    {
        $small = $this->seller();
        Livewire::actingAs($small)->test(OffsiteAds::class)->assertSee('Opt out of Offsite Ads')->call('toggle');
        $this->assertTrue($small->sellerProfile->fresh()->offsite_ads_opt_out);

        $large = $this->seller();
        $this->mock(OffsiteAdsService::class, function ($m) {
            $m->shouldReceive('canOptOut')->andReturn(false);
            $m->makePartial();
        });
        Livewire::actingAs($large)->test(OffsiteAds::class)->call('toggle');
        $this->assertFalse($large->sellerProfile->fresh()->offsite_ads_opt_out);
    }

    public function test_ledger_rows_add_up_and_pnl_shows_the_fee(): void
    {
        $seller = $this->seller();
        $this->checkout([[$this->product($seller, 100), 1]], $this->cookieFor($seller));
        OrderItem::query()->update(['status' => OrderStatus::Delivered]);

        $ledger = app(SellerLedgerService::class);
        $entries = $ledger->entries($seller->id, now()->subDay(), now()->addDay());
        $this->assertEquals(75.00, (float) $entries->sum('net')); // $100 − $10 commission − $15 ad fee
        $this->assertEquals(15.00, $ledger->pnl($seller->id, now()->subDay(), now()->addDay())['offsite_ad_fees']);
    }

    public function test_seller_page_and_order_breakdown_show_the_fee(): void
    {
        $seller = $this->seller();
        $order = $this->checkout([[$this->product($seller, 100), 1]], $this->cookieFor($seller));

        $this->actingAs($seller)->get(route('seller.offsite-ads.index'))->assertOk()->assertSee('Orders from ads')->assertSee($order->order_number);
        $this->actingAs($seller)->get(route('seller.orders.show', $order->items()->first()))->assertOk()->assertSee('Offsite Ads fee');
    }

    public function test_admin_dashboard_and_spend_recording(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $this->withTwoFactor($admin);

        $this->actingAs($admin)->get(route('admin.offsite-ads.index'))->assertOk()->assertSee('Product feeds');
        $this->actingAs($admin)->post(route('admin.offsite-ads.spend'), ['month' => now()->format('Y-m'), 'channel' => 'fb', 'amount' => 250])->assertRedirect();
        $this->actingAs($admin)->post(route('admin.offsite-ads.spend'), ['month' => now()->format('Y-m'), 'channel' => 'fb', 'amount' => 300])->assertRedirect();

        $this->assertSame(1, OffsiteAdSpend::count());
        $this->assertEquals(300, (float) OffsiteAdSpend::first()->amount);
    }
}
