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
use RuntimeException;
use Stripe\Transfer;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(AdminPermissionSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // ---------- direct login, no second step ----------

    public function test_admin_logs_in_directly_with_email_and_password(): void
    {
        $admin = User::factory()->create(['email' => 'boss@example.com', 'password' => bcrypt('password')]);
        $admin->assignRole('super_admin');
        // Accounts that had set up an authenticator before it was removed must not get stuck.
        $this->withTwoFactor($admin);

        Volt::test('pages.auth.login')
            ->set('form.email', 'boss@example.com')
            ->set('form.password', 'password')
            ->call('login')
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_seller_reaches_the_portal_directly(): void
    {
        $user = User::factory()->create();
        $user->assignRole('seller');
        SellerProfile::create([
            'user_id' => $user->id, 'business_name' => 'S', 'business_type' => 'llc', 'status' => 'approved',
        ]);

        $this->actingAs($user)->get(route('seller.dashboard'))->assertOk();
    }

    public function test_wrong_password_is_still_refused(): void
    {
        User::factory()->create(['email' => 'boss@example.com', 'password' => bcrypt('password')]);

        Volt::test('pages.auth.login')
            ->set('form.email', 'boss@example.com')
            ->set('form.password', 'wrong')
            ->call('login')
            ->assertHasErrors('form.email');

        $this->assertGuest();
    }

    public function test_two_factor_pages_are_gone(): void
    {
        foreach (['two-factor.setup', 'two-factor.challenge', 'two-factor.verify', 'two-factor.confirm', 'two-factor.disable', 'admin.users.reset-2fa'] as $name) {
            $this->assertFalse(\Illuminate\Support\Facades\Route::has($name), $name);
        }
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
