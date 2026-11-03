<?php

namespace Tests\Feature\Seller;

use App\Livewire\Seller\AdCredit;
use App\Livewire\Seller\AdManager;
use App\Models\AdCampaign;
use App\Models\AdCreditPurchase;
use App\Models\AdTransaction;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Notifications\LowAdCredit;
use App\Services\Payments\AdCreditGateway;
use App\Services\Seller\AdServingService;
use App\Services\Seller\BoostService;
use App\Services\Seller\SellerLedgerService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdCreditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seller(float $credit = 0): User
    {
        $u = User::factory()->create();
        $u->assignRole('seller');
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'Ads '.uniqid(), 'business_type' => 'llc', 'status' => 'approved', 'ad_credit' => $credit]);

        return $this->withTwoFactor($u);
    }

    private function campaign(User $seller, string $status = 'active'): array
    {
        $product = Product::create([
            'seller_id' => $seller->id,
            'category_id' => Category::create(['name' => 'C', 'slug' => 'c-'.Str::random(6)])->id,
            'title' => 'Rotor', 'slug' => 'r-'.Str::random(8), 'price' => 100, 'quantity' => 5, 'status' => 'active',
        ]);
        $campaign = AdCampaign::create([
            'seller_id' => $seller->id, 'name' => 'Push', 'objective' => 'sales', 'status' => $status, 'daily_budget' => 50,
            'targeting' => ['placements' => ['search'], 'categories' => [], 'vehicle_make_ids' => [], 'keywords' => []],
            'starts_at' => now(),
        ]);
        $creative = $campaign->creatives()->create(['product_id' => $product->id, 'is_active' => true]);

        return [$campaign, $creative];
    }

    /** Fake Stripe: configured, session creation returns a checkout URL, retrieval reports $paid. */
    private function fakeStripe(bool $paid = true, ?\Closure $createCheck = null): void
    {
        $mock = $this->mock(AdCreditGateway::class);
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('createSession')->andReturnUsing(function ($purchase, $success, $cancel) use ($createCheck) {
            $createCheck && $createCheck($purchase, $success, $cancel);

            return ['id' => 'cs_test_'.$purchase->id, 'url' => 'https://checkout.stripe.com/c/pay/cs_test_'.$purchase->id];
        });
        $mock->shouldReceive('retrieveSession')->andReturn(['paid' => $paid, 'payment_intent' => 'pi_ad_1', 'purchase_id' => null]);
    }

    public function test_buying_credit_sends_the_seller_to_stripe_checkout(): void
    {
        $this->fakeStripe(createCheck: function ($purchase, $success) {
            $this->assertEquals(100, (float) $purchase->amount);
            $this->assertStringContainsString('session_id={CHECKOUT_SESSION_ID}', $success);
        });
        $seller = $this->seller();

        Livewire::actingAs($seller)->test(AdCredit::class)
            ->call('pick', 100)
            ->call('buy')
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');

        $purchase = AdCreditPurchase::firstOrFail();
        $this->assertSame('pending', $purchase->status);
        $this->assertSame('cs_test_1', $purchase->stripe_session_id);
        $this->assertEquals(0, (float) $seller->sellerProfile->fresh()->ad_credit); // nothing granted before payment
    }

    public function test_amount_limits_are_enforced(): void
    {
        $this->fakeStripe();
        $seller = $this->seller();

        Livewire::actingAs($seller)->test(AdCredit::class)->set('amount', 5)->call('buy')->assertHasErrors('amount');
        Livewire::actingAs($seller)->test(AdCredit::class)->set('amount', 5000)->call('buy')->assertHasErrors('amount');
        $this->assertSame(0, AdCreditPurchase::count());
    }

    public function test_paid_return_grants_credit_exactly_once(): void
    {
        $this->fakeStripe(paid: true);
        $seller = $this->seller();
        Livewire::actingAs($seller)->test(AdCredit::class)->call('pick', 50)->call('buy');

        $url = route('seller.ad-credit.return').'?session_id=cs_test_1';
        $this->actingAs($seller)->get($url)->assertRedirect(route('seller.ad-credit.index'))->assertSessionHas('success');
        $this->actingAs($seller)->get($url); // refresh / back button
        app(\App\Services\Seller\AdCreditPurchaseService::class)->fulfil('cs_test_1'); // webhook arriving late

        $this->assertEquals(50, (float) $seller->sellerProfile->fresh()->ad_credit);
        $this->assertSame('paid', AdCreditPurchase::first()->status);
        $this->assertSame(1, AdTransaction::where('seller_id', $seller->id)->where('type', 'topup')->count());
    }

    public function test_unpaid_session_grants_nothing(): void
    {
        $this->fakeStripe(paid: false);
        $seller = $this->seller();
        Livewire::actingAs($seller)->test(AdCredit::class)->call('pick', 50)->call('buy');

        $this->actingAs($seller)->get(route('seller.ad-credit.return').'?session_id=cs_test_1')->assertRedirect();

        $this->assertEquals(0, (float) $seller->sellerProfile->fresh()->ad_credit);
        $this->assertSame('pending', AdCreditPurchase::first()->status);
    }

    public function test_a_seller_cannot_confirm_someone_elses_purchase(): void
    {
        $this->fakeStripe();
        $owner = $this->seller();
        Livewire::actingAs($owner)->test(AdCredit::class)->call('pick', 50)->call('buy');

        $this->actingAs($this->seller())->get(route('seller.ad-credit.return').'?session_id=cs_test_1')->assertNotFound();
        $this->assertEquals(0, (float) $owner->sellerProfile->fresh()->ad_credit);
    }

    public function test_stripe_webhook_grants_credit_when_seller_closed_the_tab(): void
    {
        $this->fakeStripe(paid: true);
        config()->set('services.stripe.webhook_secret', 'whsec_test');
        $seller = $this->seller();
        Livewire::actingAs($seller)->test(AdCredit::class)->call('pick', 25)->call('buy');

        $payload = json_encode(['id' => 'evt_ad', 'object' => 'event', 'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_test_1', 'object' => 'checkout.session', 'metadata' => ['kind' => 'ad_credit']]]]);
        $t = time();
        $this->call('POST', route('webhooks.stripe'), [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$payload}", 'whsec_test'),
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertOk();

        $this->assertEquals(25, (float) $seller->sellerProfile->fresh()->ad_credit);
    }

    public function test_unconfigured_stripe_blocks_purchase_gracefully(): void
    {
        config()->set('services.stripe.secret', null);
        $seller = $this->seller();

        Livewire::actingAs($seller)->test(AdCredit::class)
            ->assertSee("Card payments aren't switched on", false)
            ->call('buy')
            ->assertHasErrors('amount');
        $this->assertSame(0, AdCreditPurchase::count());
    }

    public function test_free_test_credit_is_blocked_on_a_live_site(): void
    {
        $seller = $this->seller();
        $this->app['env'] = 'production';

        Livewire::actingAs($seller)->test(AdCredit::class)->call('grantTestCredit')->assertForbidden();
        Livewire::actingAs($seller)->test(\App\Livewire\Seller\Marketing::class)->call('grantTestCredit')->assertForbidden();
        $this->assertEquals(0, (float) $seller->sellerProfile->fresh()->ad_credit);
    }

    public function test_running_out_pauses_campaigns_and_a_top_up_resumes_only_those(): void
    {
        Notification::fake();
        $seller = $this->seller(0.10);
        [$auto, $creative] = $this->campaign($seller);
        [$manual] = $this->campaign($seller, 'paused'); // the seller paused this one themselves

        app(AdServingService::class)->recordClick('creative', $creative->id);

        $this->assertSame('paused', $auto->fresh()->status);
        $this->assertTrue($auto->fresh()->paused_for_credit);
        Notification::assertSentTo($seller, LowAdCredit::class, fn ($n) => $n->paused === true);

        app(BoostService::class)->grantCredit($seller, 50, 'Ad credit purchase — card', 'topup');

        $this->assertSame('active', $auto->fresh()->status);
        $this->assertFalse($auto->fresh()->paused_for_credit);
        $this->assertSame('paused', $manual->fresh()->status); // seller's own pause is respected
    }

    public function test_low_balance_warning_fires_once_when_crossing_the_line(): void
    {
        Notification::fake();
        $seller = $this->seller(5.10);
        [, $creative] = $this->campaign($seller);
        $ads = app(AdServingService::class);

        $ads->recordClick('creative', $creative->id); // 5.10 -> 4.85: crosses below $5
        $ads->recordClick('creative', $creative->id); // 4.85 -> 4.60: already below, no repeat

        Notification::assertSentToTimes($seller, LowAdCredit::class, 1);
    }

    public function test_ads_are_not_shown_when_the_seller_cannot_afford_a_click(): void
    {
        $broke = $this->seller(0.10);
        [, $brokeCreative] = $this->campaign($broke);
        $funded = $this->seller(20);
        [, $fundedCreative] = $this->campaign($funded);

        $served = app(AdServingService::class)->forPlacement('search');

        $this->assertFalse($served->contains(fn ($s) => $s['ref_id'] === $brokeCreative->id));
        $this->assertTrue($served->contains(fn ($s) => $s['ref_id'] === $fundedCreative->id));
    }

    public function test_manual_resume_clears_the_out_of_credit_flag(): void
    {
        $seller = $this->seller(0);
        [$campaign] = $this->campaign($seller, 'paused');
        $campaign->update(['paused_for_credit' => true]);

        Livewire::actingAs($seller)->test(AdManager::class)->call('toggle', $campaign->id);

        $this->assertSame('active', $campaign->fresh()->status);
        $this->assertFalse($campaign->fresh()->paused_for_credit);
    }

    public function test_buying_credit_is_not_counted_as_earnings_but_spend_is_a_cost(): void
    {
        $seller = $this->seller(0);
        app(BoostService::class)->grantCredit($seller, 100, 'Ad credit purchase — card', 'topup');
        [, $creative] = $this->campaign($seller);
        app(AdServingService::class)->recordClick('creative', $creative->id); // −$0.25

        $pnl = app(SellerLedgerService::class)->pnl($seller->id, now()->subDay(), now()->addDay());

        $this->assertEquals(0.25, $pnl['ad_spend']);
        $this->assertEquals(-0.25, $pnl['net_earnings']); // not +$99.75
    }

    public function test_ad_credit_page_renders_with_history(): void
    {
        $seller = $this->seller(0);
        app(BoostService::class)->grantCredit($seller, 40, 'Ad credit purchase — card', 'topup');

        $this->actingAs($seller)->get(route('seller.ad-credit.index'))
            ->assertOk()->assertSee('Ad credit purchase — card')->assertSee('$40.00');
    }
}
