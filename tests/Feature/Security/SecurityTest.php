<?php

namespace Tests\Feature\Security;

use App\Enums\OrderStatus;
use App\Jobs\ReleaseSellerPayout;
use App\Models\AdminAlert;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Marketing\LoyaltyService;
use App\Services\Marketing\WalletService;
use App\Services\Payments\TransferPayoutService;
use Database\Seeders\AdminPermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;
use Stripe\Transfer;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'JBSWY3DPEHPK3PXP';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(AdminPermissionSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // ---------- 2FA enrolment gate ----------

    public function test_seller_without_2fa_is_forced_to_setup(): void
    {
        $user = User::factory()->create();
        $user->assignRole('seller');
        SellerProfile::create([
            'user_id' => $user->id, 'business_name' => 'S', 'business_type' => 'llc', 'status' => 'approved',
        ]);

        $this->actingAs($user)
            ->get(route('seller.dashboard'))
            ->assertRedirect(route('two-factor.setup'));
    }

    public function test_plain_buyer_is_not_forced_into_2fa(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk();
    }

    // ---------- 2FA enrolment flow ----------

    public function test_user_can_enrol_in_2fa_with_a_valid_totp(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['2fa.pending_secret' => self::SECRET])
            ->post(route('two-factor.confirm'), ['code' => app(Google2FA::class)->getCurrentOtp(self::SECRET)])
            ->assertRedirect(route('two-factor.setup'))
            ->assertSessionHas('recovery_codes');

        $user->refresh();
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertNotNull($user->two_factor_secret);
        $this->assertCount(8, $user->two_factor_recovery_codes);
    }

    public function test_enrolment_rejects_a_bad_code(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['2fa.pending_secret' => self::SECRET])
            ->from(route('two-factor.setup'))
            ->post(route('two-factor.confirm'), ['code' => '000000'])
            ->assertRedirect(route('two-factor.setup'))
            ->assertSessionHasErrors('code');

        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }

    // ---------- 2FA login challenge ----------

    public function test_login_with_2fa_enabled_stops_at_the_challenge(): void
    {
        $user = User::factory()->create(['email' => '2fa@example.com', 'password' => bcrypt('password')]);
        $this->withTwoFactor($user);

        Volt::test('pages.auth.login')
            ->set('form.email', '2fa@example.com')
            ->set('form.password', 'password')
            ->call('login')
            ->assertRedirect(route('two-factor.challenge'));

        $this->assertGuest();
        $this->assertSame($user->id, session('2fa.user'));
    }

    public function test_challenge_completes_login_with_a_valid_code(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => self::SECRET,
            'two_factor_recovery_codes' => ['recover-me-01'],
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->withSession(['2fa.user' => $user->id])
            ->post(route('two-factor.verify'), ['code' => app(Google2FA::class)->getCurrentOtp(self::SECRET)])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_recovery_code_works_once(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => self::SECRET,
            'two_factor_recovery_codes' => ['recover-me-01', 'recover-me-02'],
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->withSession(['2fa.user' => $user->id])
            ->post(route('two-factor.verify'), ['code' => 'recover-me-01'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(['recover-me-02'], $user->fresh()->two_factor_recovery_codes);
    }

    // ---------- rate limiting ----------

    public function test_checkout_is_rate_limited(): void
    {
        $buyer = User::factory()->create();

        // 10/min limiter → the 11th rapid POST is throttled.
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($buyer)->post(route('checkout.store'), []);
        }

        $this->actingAs($buyer)->post(route('checkout.store'), [])->assertStatus(429);
    }

    public function test_support_chat_stops_calling_ai_when_rate_limited(): void
    {
        config()->set('services.anthropic.key', null);
        $buyer = User::factory()->create();
        RateLimiter::clear('ai-chat:'.$buyer->id);

        for ($i = 0; $i < 20; $i++) {
            RateLimiter::hit('ai-chat:'.$buyer->id, 60);
        }

        $component = \Livewire\Livewire::actingAs($buyer)
            ->test(\App\Livewire\Storefront\SupportChat::class)
            ->set('input', 'hello?')
            ->call('send');

        $messages = $component->get('messages');
        $this->assertStringContainsString('too quickly', end($messages)['content']);
    }

    // ---------- money-path: payout job hardening ----------

    public function test_payout_job_marks_released_only_after_cashback(): void
    {
        $seller = User::factory()->create();
        SellerProfile::create([
            'user_id' => $seller->id, 'business_name' => 'S', 'business_type' => 'llc',
            'status' => 'approved', 'stripe_account_id' => 'acct_1', 'stripe_onboarding_complete' => true,
        ]);

        $buyer = User::factory()->create();
        $order = Order::create([
            'order_number' => 'PM-'.strtoupper(Str::random(8)), 'buyer_id' => $buyer->id,
            'status' => OrderStatus::Paid, 'subtotal' => 100, 'total' => 100,
            'shipping_address' => ['x' => 1], 'billing_address' => ['x' => 1],
        ]);
        $item = $order->items()->create([
            'seller_id' => $seller->id,
            'product_id' => Product::create([
                'seller_id' => $seller->id,
                'category_id' => Category::create(['name' => 'C', 'slug' => 'c-'.Str::random(5)])->id,
                'title' => 'W', 'slug' => 'w-'.Str::random(5), 'price' => 100, 'quantity' => 1,
            ])->id,
            'product_title' => 'W', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100,
            'commission_rate' => 10, 'platform_fee_amount' => 10, 'seller_payout_amount' => 90,
            'status' => OrderStatus::Delivered, 'delivered_at' => now(),
        ]);

        $this->mock(TransferPayoutService::class)
            ->shouldReceive('transferToSeller')->once()
            ->andReturn(Transfer::constructFrom(['id' => 'tr_1']));

        (new ReleaseSellerPayout($item->id))->handle(
            app(TransferPayoutService::class), app(WalletService::class), app(LoyaltyService::class)
        );

        $item->refresh();
        $this->assertSame(OrderStatus::PayoutReleased, $item->status);
        $this->assertNotNull($item->payout_released_at);
        $this->assertNotNull($item->cashback_credited_at);
        $this->assertEquals(1.00, $buyer->fresh()->wallet_balance);
    }

    public function test_failed_payout_job_raises_an_admin_alert(): void
    {
        (new ReleaseSellerPayout(999))->failed(new RuntimeException('stripe down'));

        $this->assertDatabaseHas('admin_alerts', [
            'type' => 'payout_job_failed',
            'related_order_item_id' => null, // item 999 doesn't exist — alert still recorded
        ]);
    }
}
