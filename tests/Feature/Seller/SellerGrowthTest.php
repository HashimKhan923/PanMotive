<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Livewire\Seller\Marketing;
use App\Livewire\Seller\Offers as OffersComponent;
use App\Livewire\Storefront\FollowStore;
use App\Livewire\Storefront\MakeOffer;
use App\Models\Boost;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoLink;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Seller\PriceIntelligenceService;
use App\Services\Seller\SellerScorecardService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SellerGrowthTest extends TestCase
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
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'Acme '.uniqid(), 'business_type' => 'llc', 'status' => 'approved']);

        return $this->withTwoFactor($u);
    }

    private function product(User $seller, array $o = []): Product
    {
        return Product::create(array_merge([
            'seller_id' => $seller->id,
            'category_id' => Category::create(['name' => 'C'.uniqid(), 'slug' => 'c-'.uniqid()])->id,
            'title' => 'Widget', 'slug' => 'widget-'.uniqid(),
            'price' => 100, 'quantity' => 10, 'status' => 'active',
        ], $o));
    }

    /* ---------- Scorecard & tiers ---------- */

    public function test_scorecard_computes_metrics_and_page_renders(): void
    {
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $product = $this->product($seller);

        // 12 delivered, on-time-shipped orders → should clear "Rising Seller".
        foreach (range(1, 12) as $i) {
            $order = Order::create([
                'order_number' => 'PM-'.strtoupper(uniqid()), 'buyer_id' => $buyer->id,
                'subtotal' => 100, 'total' => 100,
                'shipping_address' => ['line1' => 'x'], 'billing_address' => ['line1' => 'x'],
                'status' => OrderStatus::Delivered,
            ]);
            $order->items()->create([
                'seller_id' => $seller->id, 'product_id' => $product->id, 'product_title' => 'Widget',
                'quantity' => 1, 'unit_price' => 100, 'line_total' => 100, 'commission_rate' => 10,
                'platform_fee_amount' => 10, 'seller_payout_amount' => 90,
                'status' => OrderStatus::Delivered,
                'shipped_at' => now()->subDays(5), 'created_at' => now()->subDays(6),
            ]);
        }

        $m = app(SellerScorecardService::class)->metrics($seller);
        $this->assertSame(12, $m['fulfilled_orders']);
        $this->assertSame(100.0, $m['on_time_ship_rate']);
        $this->assertContains($m['tier'], ['rising', 'top_rated', 'powerseller']);

        $this->actingAs($seller)->get(route('seller.scorecard.index'))->assertOk()->assertSee('Performance');

        // Tier persisted + commission discount applied.
        $this->assertSame($m['tier'], $seller->sellerProfile->fresh()->tier);
        $this->assertLessThan(10.0, $seller->sellerProfile->fresh()->commissionRate());
    }

    /* ---------- Offers ---------- */

    public function test_offer_lifecycle_accept_mints_single_use_code(): void
    {
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $product = $this->product($seller, ['price' => 100, 'accepts_offers' => true]);

        Livewire::actingAs($buyer)->test(MakeOffer::class, ['product' => $product])
            ->set('price', 80)->set('quantity', 2)
            ->call('submit')->assertHasNoErrors();

        $offer = Offer::firstOrFail();
        $this->assertSame('pending', $offer->status);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $seller->id]);

        Livewire::actingAs($seller)->test(OffersComponent::class)
            ->call('accept', $offer->id);

        $offer->refresh();
        $this->assertSame('accepted', $offer->status);
        $this->assertNotNull($offer->coupon_code);

        $coupon = Coupon::where('code', $offer->coupon_code)->firstOrFail();
        $this->assertSame($seller->id, $coupon->seller_id);
        $this->assertSame(1, $coupon->usage_limit);
        // (100 - 80) * 2 units
        $this->assertEquals(40.0, (float) $coupon->value);
    }

    public function test_offer_rejected_when_listing_not_accepting(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller, ['accepts_offers' => false]);

        Livewire::actingAs(User::factory()->create())->test(MakeOffer::class, ['product' => $product])
            ->set('price', 50)->call('submit')
            ->assertStatus(422);
    }

    /* ---------- Boosts / ad credit ---------- */

    public function test_boost_debits_ad_credit_and_prioritises_product(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller);

        $c = Livewire::actingAs($seller)->test(Marketing::class)
            ->call('grantTestCredit')
            ->set('boostProductId', $product->id)
            ->set('boostPlacement', 'search')
            ->set('boostDays', 5)
            ->call('startBoost')
            ->assertHasNoErrors();

        $profile = $seller->sellerProfile->fresh();
        $this->assertEquals(25.00 - (3.00 * 5), (float) $profile->ad_credit); // $15 spent
        $this->assertDatabaseHas('boosts', ['product_id' => $product->id, 'status' => 'active', 'placement' => 'search']);
        $this->assertDatabaseHas('ad_transactions', ['seller_id' => $seller->id, 'type' => 'boost_spend']);

        $this->assertTrue(Product::boosted('search')->where('id', $product->id)->exists());
    }

    public function test_boost_blocked_without_credit(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller);

        Livewire::actingAs($seller)->test(Marketing::class)
            ->set('boostProductId', $product->id)
            ->set('boostDays', 10)
            ->call('startBoost')
            ->assertHasErrors('boost');

        $this->assertDatabaseCount('boosts', 0);
    }

    /* ---------- Follow store ---------- */

    public function test_follow_and_unfollow_store(): void
    {
        $seller = $this->seller();
        $buyer = User::factory()->create();

        Livewire::actingAs($buyer)->test(FollowStore::class, ['sellerId' => $seller->id])
            ->assertSet('following', false)
            ->call('toggle')
            ->assertSet('following', true)
            ->assertSet('count', 1)
            ->call('toggle')
            ->assertSet('following', false);

        $this->assertDatabaseCount('seller_followers', 0);
    }

    /* ---------- Promo links ---------- */

    public function test_promo_link_redirects_and_counts_clicks(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller);
        $link = PromoLink::create(['seller_id' => $seller->id, 'product_id' => $product->id]);

        $this->get(route('promo.go', $link->code))
            ->assertRedirect(route('products.show', $product->slug))
            ->assertCookie('pm_ref', $link->code);

        $this->assertSame(1, $link->fresh()->clicks);
    }

    /* ---------- Price intelligence ---------- */

    public function test_price_intelligence_ranks_against_other_sellers(): void
    {
        $a = $this->seller();
        $b = $this->seller();
        $cat = Category::create(['name' => 'Pads', 'slug' => 'pads-'.uniqid()]);

        $mine = Product::create(['seller_id' => $a->id, 'category_id' => $cat->id, 'title' => 'Pad', 'slug' => 'p-'.uniqid(), 'price' => 60, 'quantity' => 5, 'status' => 'active', 'part_number' => 'BP-99']);
        Product::create(['seller_id' => $b->id, 'category_id' => $cat->id, 'title' => 'Pad', 'slug' => 'p-'.uniqid(), 'price' => 50, 'quantity' => 5, 'status' => 'active', 'part_number' => 'BP-99']);
        Product::create(['seller_id' => $b->id, 'category_id' => $cat->id, 'title' => 'Pad', 'slug' => 'p-'.uniqid(), 'price' => 65, 'quantity' => 5, 'status' => 'active', 'part_number' => 'BP-99']);

        $intel = app(PriceIntelligenceService::class)->forProduct($mine);

        $this->assertSame(2, $intel['count']);
        $this->assertSame(50.0, $intel['min']);
        $this->assertSame('above', $intel['position']);
        $this->assertEqualsWithDelta(20.0, $intel['delta_pct'], 0.1);
    }

    public function test_listing_quality_score_reflects_completeness(): void
    {
        $seller = $this->seller();
        $bare = $this->product($seller, ['description' => null, 'part_number' => null, 'brand_id' => null, 'weight_lbs' => null]);
        $this->assertLessThan(40, $bare->qualityScore()['score']);
    }
}
