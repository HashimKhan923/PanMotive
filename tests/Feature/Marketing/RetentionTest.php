<?php

namespace Tests\Feature\Marketing;

use App\Console\Commands\SendAbandonedCartReminders;
use App\Enums\OrderStatus;
use App\Jobs\ReleaseSellerPayout;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Referral;
use App\Models\SellerProfile;
use App\Models\User;
use App\Notifications\AbandonedCartReminder;
use App\Notifications\LoyaltyTierUpgraded;
use App\Services\Cart\CartService;
use App\Services\Marketing\CouponService;
use App\Services\Marketing\LoyaltyService;
use App\Services\Marketing\WalletService;
use App\Services\Payments\CheckoutPaymentService;
use App\Services\Payments\TransferPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Stripe\PaymentIntent;
use Stripe\Transfer;
use Tests\TestCase;

class RetentionTest extends TestCase
{
    use RefreshDatabase;

    // ---------- DB cart ----------

    public function test_cart_add_persists_to_db_and_merges_on_login(): void
    {
        $product = $this->product();

        // Guest adds → session cart row.
        $this->post(route('cart.add'), ['product_id' => $product->id, 'quantity' => 2])->assertRedirect();
        $this->assertDatabaseHas('carts', ['session_id' => session('cart_session_id')]);

        // Logging in merges the guest cart into the user cart.
        $user = User::factory()->create();
        $this->actingAs($user);
        $lines = app(CartService::class)->lines();

        $this->assertCount(1, $lines);
        $this->assertSame(2, $lines->first()['quantity']);
        $this->assertDatabaseHas('carts', ['user_id' => $user->id]);
    }

    // ---------- cashback off the delivery trigger ----------

    public function test_cashback_and_lifetime_spend_credit_when_payout_releases(): void
    {
        $seller = $this->readySeller();
        $item = $this->deliveredItem($seller, lineTotal: 100);
        $buyer = $item->order->buyer;

        $this->mockTransfer();
        $this->runPayout($item);

        $item->refresh();
        $this->assertEquals(1.00, $item->cashback_amount); // bronze = 1%
        $this->assertNotNull($item->cashback_credited_at);
        $this->assertEquals(1.00, $buyer->fresh()->wallet_balance);
        $this->assertEquals(100, $buyer->fresh()->lifetime_spend);
        $this->assertDatabaseHas('credit_transactions', ['user_id' => $buyer->id, 'type' => 'cashback', 'amount' => 1.00]);
    }

    public function test_cashback_is_not_double_credited_on_retry(): void
    {
        $seller = $this->readySeller();
        $item = $this->deliveredItem($seller, lineTotal: 100);
        $buyer = $item->order->buyer;

        $this->mockTransfer();
        $this->runPayout($item);

        // Second run: item is PayoutReleased now → job early-returns, balance unchanged.
        (new ReleaseSellerPayout($item->id))->handle(
            app(TransferPayoutService::class), app(WalletService::class), app(LoyaltyService::class)
        );

        $this->assertEquals(1.00, $buyer->fresh()->wallet_balance);
        $this->assertSame(1, $buyer->creditTransactions()->count());
    }

    public function test_loyalty_tier_upgrades_when_lifetime_spend_crosses_threshold(): void
    {
        Notification::fake();
        $seller = $this->readySeller();
        $item = $this->deliveredItem($seller, lineTotal: 100);
        $item->order->buyer->update(['lifetime_spend' => 450]);

        $this->mockTransfer();
        $this->runPayout($item);

        $this->assertSame('silver', $item->order->buyer->fresh()->loyalty_tier);
        Notification::assertSentTo($item->order->buyer, LoyaltyTierUpgraded::class);
    }

    // ---------- coupons ----------

    public function test_coupon_validation_rules(): void
    {
        $svc = app(CouponService::class);

        $pct = Coupon::create(['code' => 'SAVE10', 'type' => 'percentage', 'value' => 10, 'is_active' => true]);
        $this->assertTrue($svc->validate('save10', null, 200)['valid']);
        $this->assertEquals(20.0, $svc->validate('SAVE10', null, 200)['discount']);

        $expired = Coupon::create(['code' => 'OLD', 'type' => 'fixed', 'value' => 5, 'is_active' => true, 'expires_at' => now()->subDay()]);
        $this->assertFalse($svc->validate('OLD', null, 100)['valid']);

        $min = Coupon::create(['code' => 'BIG', 'type' => 'fixed', 'value' => 25, 'is_active' => true, 'min_order_total' => 300]);
        $this->assertFalse($svc->validate('BIG', null, 100)['valid']);

        $capped = Coupon::create(['code' => 'CAP', 'type' => 'percentage', 'value' => 50, 'is_active' => true, 'max_discount_amount' => 15]);
        $this->assertEquals(15.0, $svc->validate('CAP', null, 200)['discount']);
    }

    public function test_checkout_applies_coupon_and_records_redemption(): void
    {
        $this->mock(CheckoutPaymentService::class)->shouldReceive('createPaymentIntent')
            ->andReturn(PaymentIntent::constructFrom(['id' => 'pi_x', 'client_secret' => 'cs_x']));

        $buyer = User::factory()->create();
        $product = $this->product(price: 100);
        $this->cartFor($buyer, $product, 1);
        Coupon::create(['code' => 'SAVE10', 'type' => 'percentage', 'value' => 10, 'is_active' => true, 'usage_limit' => 5]);

        $this->actingAs($buyer)->post(route('checkout.apply-coupon'), ['code' => 'SAVE10'])->assertRedirect();
        $this->actingAs($buyer)->post(route('checkout.store'), $this->address())->assertOk();

        $order = Order::firstOrFail();
        $this->assertEquals(100, $order->subtotal);
        $this->assertEquals(10, $order->discount_total);
        $this->assertEquals(90, $order->total);
        $this->assertNotNull($order->coupon_id);
        $this->assertDatabaseHas('coupon_usages', ['order_id' => $order->id, 'discount_amount' => 10]);
        $this->assertSame(1, Coupon::firstOrFail()->times_used);
    }

    // ---------- store credit ----------

    public function test_checkout_redeems_credits_capped_at_half_the_order(): void
    {
        $this->mock(CheckoutPaymentService::class)->shouldReceive('createPaymentIntent')
            ->andReturn(PaymentIntent::constructFrom(['id' => 'pi_x', 'client_secret' => 'cs_x']));

        $buyer = User::factory()->create(['wallet_balance' => 500]);
        $product = $this->product(price: 100);
        $this->cartFor($buyer, $product, 1);

        $this->actingAs($buyer)->post(route('checkout.store'), $this->address() + ['use_credits' => '1'])->assertOk();

        $order = Order::firstOrFail();
        $this->assertEquals(50, $order->credits_used); // capped at 50% of $100
        $this->assertEquals(50, $order->total);
        $this->assertEquals(450, $buyer->fresh()->wallet_balance);
        $this->assertDatabaseHas('credit_transactions', ['user_id' => $buyer->id, 'type' => 'redeemed', 'amount' => -50]);
    }

    public function test_fully_credit_covered_order_skips_stripe_and_is_marked_paid(): void
    {
        $this->mock(CheckoutPaymentService::class)->shouldReceive('createPaymentIntent')->never();

        $buyer = User::factory()->create(['wallet_balance' => 500]);
        $product = $this->product(price: 100);
        $this->cartFor($buyer, $product, 1);
        // 100% coupon zeroes the order; credits then have nothing to apply to.
        Coupon::create(['code' => 'FREE', 'type' => 'percentage', 'value' => 100, 'is_active' => true]);
        $this->actingAs($buyer)->post(route('checkout.apply-coupon'), ['code' => 'FREE']);

        $this->actingAs($buyer)->post(route('checkout.store'), $this->address())
            ->assertRedirect(route('checkout.confirmation', Order::firstOrFail()->order_number));

        $this->assertSame(OrderStatus::Paid, Order::firstOrFail()->status);
    }

    public function test_wallet_debit_rejects_overdraw(): void
    {
        $user = User::factory()->create(['wallet_balance' => 5]);
        $this->expectException(RuntimeException::class);
        app(WalletService::class)->debit($user, 10, 'nope');
    }

    // ---------- referrals ----------

    public function test_referral_signup_and_first_order_pays_both_parties(): void
    {
        config()->set('services.stripe.webhook_secret', 'whsec_test');
        $seller = $this->readySeller();

        $referrer = User::factory()->create(['referral_code' => 'FRIEND01']);

        // Volt register component with a referral code in state.
        \Livewire\Volt\Volt::test('pages.auth.register')
            ->set('ref', 'FRIEND01')
            ->set('name', 'New Buyer')
            ->set('email', 'nb@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('register');

        $buyer = User::where('email', 'nb@example.com')->firstOrFail();
        $this->assertDatabaseHas('referrals', ['referrer_id' => $referrer->id, 'referred_user_id' => $buyer->id, 'status' => 'pending']);

        // Buyer's first paid order → webhook completes the referral.
        $order = Order::create([
            'order_number' => 'PM-'.strtoupper(Str::random(8)), 'buyer_id' => $buyer->id,
            'status' => OrderStatus::Placed, 'subtotal' => 100, 'total' => 100,
            'shipping_address' => ['x' => 1], 'billing_address' => ['x' => 1],
            'stripe_payment_intent_id' => 'pi_'.Str::random(10),
        ]);
        $order->items()->create($this->itemAttrs($seller));

        $this->postStripe($this->stripeEvent($order->stripe_payment_intent_id))->assertOk();

        $this->assertDatabaseHas('referrals', ['referred_user_id' => $buyer->id, 'status' => 'completed']);
        $this->assertEquals(10, $referrer->fresh()->wallet_balance);
        $this->assertEquals(10, $buyer->fresh()->wallet_balance);
        $this->assertDatabaseHas('credit_transactions', ['user_id' => $referrer->id, 'type' => 'referral_bonus']);
    }

    // ---------- abandoned cart ----------

    public function test_abandoned_cart_command_reminds_once(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $product = $this->product();

        $cart = Cart::create(['user_id' => $user->id]);
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 1]);
        $cart->forceFill(['updated_at' => now()->subHours(6)])->saveQuietly();

        $freshUser = User::factory()->create();
        $freshCart = Cart::create(['user_id' => $freshUser->id]);
        $freshCart->items()->create(['product_id' => $product->id, 'quantity' => 1]);

        $this->artisan(SendAbandonedCartReminders::class)->assertOk();

        Notification::assertSentTo($user, AbandonedCartReminder::class);
        Notification::assertNotSentTo($freshUser, AbandonedCartReminder::class);
        $this->assertNotNull($cart->fresh()->abandoned_reminder_sent_at);

        // Re-running does not remind again.
        Notification::fake();
        $this->artisan(SendAbandonedCartReminders::class)->assertOk();
        Notification::assertNothingSent();
    }

    public function test_wallet_page_renders_with_balance_and_referral_link(): void
    {
        $user = User::factory()->create(['wallet_balance' => 42.50]);

        $this->actingAs($user)->get(route('wallet.index'))
            ->assertOk()
            ->assertSee('42.50')
            ->assertSee('/register?ref=');

        $this->assertNotNull($user->fresh()->referral_code);
    }

    // ---------- helpers ----------

    private function address(): array
    {
        return ['name' => 'J', 'line1' => '1 A St', 'city' => 'X', 'state' => 'CA', 'postal_code' => '90001', 'country' => 'US'];
    }

    private function product(float $price = 50): Product
    {
        return Product::create([
            'seller_id' => User::factory()->create()->id,
            'category_id' => Category::create(['name' => 'C', 'slug' => 'c-'.Str::random(5)])->id,
            'title' => 'Widget', 'slug' => 'w-'.Str::random(5),
            'price' => $price, 'quantity' => 10, 'status' => 'active',
        ]);
    }

    private function cartFor(User $user, Product $product, int $qty): void
    {
        $cart = Cart::create(['user_id' => $user->id]);
        $cart->items()->create(['product_id' => $product->id, 'quantity' => $qty]);
    }

    private function readySeller(): User
    {
        $u = User::factory()->create();
        SellerProfile::create([
            'user_id' => $u->id, 'business_name' => 'S', 'business_type' => 'llc',
            'status' => 'approved', 'stripe_account_id' => 'acct_1', 'stripe_onboarding_complete' => true,
        ]);

        return $u;
    }

    private function itemAttrs(User $seller, float $lineTotal = 100): array
    {
        return [
            'seller_id' => $seller->id,
            'product_id' => $this->product()->id,
            'product_title' => 'W', 'quantity' => 1, 'unit_price' => $lineTotal, 'line_total' => $lineTotal,
            'commission_rate' => 10, 'platform_fee_amount' => round($lineTotal * 0.1, 2),
            'seller_payout_amount' => round($lineTotal * 0.9, 2), 'status' => OrderStatus::Placed,
        ];
    }

    private function deliveredItem(User $seller, float $lineTotal = 100): OrderItem
    {
        $order = Order::create([
            'order_number' => 'PM-'.strtoupper(Str::random(8)),
            'buyer_id' => User::factory()->create()->id,
            'status' => OrderStatus::Paid, 'subtotal' => $lineTotal, 'total' => $lineTotal,
            'shipping_address' => ['x' => 1], 'billing_address' => ['x' => 1],
        ]);

        return $order->items()->create(array_merge($this->itemAttrs($seller, $lineTotal), [
            'status' => OrderStatus::Delivered, 'delivered_at' => now(),
        ]));
    }

    private function mockTransfer(): void
    {
        $this->mock(TransferPayoutService::class)
            ->shouldReceive('transferToSeller')
            ->andReturn(Transfer::constructFrom(['id' => 'tr_1']));
    }

    private function runPayout(OrderItem $item): void
    {
        (new ReleaseSellerPayout($item->id))->handle(
            app(TransferPayoutService::class), app(WalletService::class), app(LoyaltyService::class)
        );
    }

    private function stripeEvent(string $pi): string
    {
        return json_encode([
            'id' => 'evt_'.Str::random(6), 'object' => 'event', 'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => $pi, 'object' => 'payment_intent']],
        ]);
    }

    private function postStripe(string $payload)
    {
        $t = time();
        $sig = hash_hmac('sha256', "{$t}.{$payload}", config('services.stripe.webhook_secret'));

        return $this->call('POST', route('webhooks.stripe'), [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$t},v1={$sig}", 'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }
}
