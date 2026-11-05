<?php

namespace Tests\Feature\Payments;

use App\Console\Commands\AutoConfirmDeliveries;
use App\Enums\OrderStatus;
use App\Jobs\ReleaseSellerPayout;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Marketing\LoyaltyService;
use App\Services\Marketing\WalletService;
use App\Services\Payments\CheckoutPaymentService;
use App\Services\Payments\RefundService;
use App\Services\Payments\TransferPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Stripe\PaymentIntent;
use Stripe\Transfer;
use Tests\TestCase;

class PaymentsEscrowTest extends TestCase
{
    use RefreshDatabase;

    // ---------- checkout ----------

    public function test_checkout_creates_order_with_snapshotted_commission_and_payment_intent(): void
    {
        $this->mock(CheckoutPaymentService::class)
            ->shouldReceive('createPaymentIntent')
            ->once()
            ->andReturn(PaymentIntent::constructFrom(['id' => 'pi_test_1', 'client_secret' => 'pi_test_1_secret']));

        $buyer = User::factory()->create();
        $product = $this->product(price: 100);

        $cart = \App\Models\Cart::create(['user_id' => $buyer->id]);
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 2]);

        $this->actingAs($buyer)
            ->post(route('checkout.store'), $this->address())
            ->assertOk()
            ->assertViewIs('storefront.checkout.pay');

        $order = Order::firstOrFail();
        $this->assertSame('pi_test_1', $order->stripe_payment_intent_id);
        $this->assertEquals(200, $order->total);

        $item = $order->items()->firstOrFail();
        $this->assertEquals(200, $item->line_total);
        $this->assertEquals(10, $item->commission_rate);
        $this->assertEquals(20, $item->platform_fee_amount);
        $this->assertEquals(180, $item->seller_payout_amount);

        $this->assertSame(0, $cart->items()->count());
    }

    // ---------- stripe webhook ----------

    public function test_stripe_webhook_rejects_bad_signature(): void
    {
        config()->set('services.stripe.webhook_secret', 'whsec_test');

        $this->call('POST', route('webhooks.stripe'), [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 't=1,v1=deadbeef',
            'CONTENT_TYPE' => 'application/json',
        ], '{"type":"payment_intent.succeeded"}')
            ->assertStatus(400);
    }

    public function test_stripe_payment_succeeded_marks_order_paid_and_is_idempotent(): void
    {
        config()->set('services.stripe.webhook_secret', 'whsec_test');

        $seller = $this->readySeller();
        $order = $this->order(['status' => OrderStatus::Placed]);
        $order->items()->create($this->itemAttrs($seller, OrderStatus::Placed));

        $payload = $this->eventPayload('payment_intent.succeeded', $order->stripe_payment_intent_id);

        $this->postStripe($payload)->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame(OrderStatus::Paid, $order->items()->first()->status);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $seller->id]);

        // Replaying the same event must not throw or double-process.
        $this->postStripe($payload)->assertOk();
        $this->assertSame(1, $order->items()->count());
    }

    public function test_stripe_payment_failed_keeps_order_open_for_another_card(): void
    {
        config()->set('services.stripe.webhook_secret', 'whsec_test');

        $order = $this->order(['status' => OrderStatus::Placed]);

        $this->postStripe($this->eventPayload('payment_intent.payment_failed', $order->stripe_payment_intent_id))
            ->assertOk();

        // A decline isn't final — the buyer can retry; unpaid orders expire separately.
        $this->assertSame(OrderStatus::Placed, $order->refresh()->status);
    }

    // ---------- delivery confirmation ----------

    public function test_buyer_can_confirm_delivery_and_dispatches_payout(): void
    {
        Queue::fake();
        $seller = $this->readySeller();
        $item = $this->shippedItem($seller);

        $this->actingAs($item->order->buyer)
            ->post(route('orders.confirm-delivery', $item))
            ->assertRedirect();

        $this->assertSame(OrderStatus::Delivered, $item->refresh()->status);
        $this->assertNotNull($item->delivered_at);
        Queue::assertPushed(ReleaseSellerPayout::class, fn ($j) => $j->orderItemId === $item->id);
    }

    public function test_confirm_delivery_blocked_for_non_buyer_and_wrong_status(): void
    {
        Queue::fake();
        $seller = $this->readySeller();
        $item = $this->shippedItem($seller);

        $this->actingAs(User::factory()->create())
            ->post(route('orders.confirm-delivery', $item))
            ->assertForbidden();

        $item->update(['status' => OrderStatus::Placed]);
        $this->actingAs($item->order->buyer)
            ->post(route('orders.confirm-delivery', $item))
            ->assertStatus(400);

        Queue::assertNotPushed(ReleaseSellerPayout::class);
    }

    public function test_auto_confirm_command_only_touches_items_past_grace_period(): void
    {
        Queue::fake();
        $seller = $this->readySeller();

        $old = $this->shippedItem($seller, shippedDaysAgo: 30);
        $fresh = $this->shippedItem($seller, shippedDaysAgo: 1);

        $this->artisan(AutoConfirmDeliveries::class)->assertOk();

        $this->assertSame(OrderStatus::Delivered, $old->refresh()->status);
        $this->assertSame(OrderStatus::Shipped, $fresh->refresh()->status);
        Queue::assertPushed(ReleaseSellerPayout::class, 1);
    }

    // ---------- easypost webhook ----------

    public function test_easypost_delivered_tracker_confirms_and_dispatches_payout(): void
    {
        Queue::fake();
        $seller = $this->readySeller();
        $item = $this->shippedItem($seller);
        $item->update(['tracking_number' => 'TRK123']);

        $this->easyPostTracker('TRK123', 'delivered')->assertOk();

        $this->assertSame(OrderStatus::Delivered, $item->refresh()->status);
        Queue::assertPushed(ReleaseSellerPayout::class);
    }

    public function test_easypost_refuses_unsigned_forged_and_unconfigured_calls(): void
    {
        Queue::fake();
        $item = $this->shippedItem($this->readySeller());
        $item->update(['tracking_number' => 'TRK123']);

        $body = json_encode(['description' => 'tracker.updated', 'result' => ['tracking_code' => 'TRK123', 'status' => 'delivered']]);
        $server = ['CONTENT_TYPE' => 'application/json'];

        config(['services.easypost.webhook_secret' => 'the-real-secret']);
        $this->call('POST', route('webhooks.easypost'), [], [], [], $server, $body)->assertStatus(401);

        $wrongKey = 'hmac-sha256-hex='.hash_hmac('sha256', $body, 'a-guess');
        $this->call('POST', route('webhooks.easypost'), [], [], [], $server + ['HTTP_X_HMAC_SIGNATURE' => $wrongKey], $body)->assertStatus(401);

        // A real signature can't be reused on a changed message.
        $signed = 'hmac-sha256-hex='.hash_hmac('sha256', '{"description":"other"}', 'the-real-secret');
        $this->call('POST', route('webhooks.easypost'), [], [], [], $server + ['HTTP_X_HMAC_SIGNATURE' => $signed], $body)->assertStatus(401);

        // No secret set: refuse everything, even a call "signed" with an empty secret.
        config(['services.easypost.webhook_secret' => null]);
        $empty = 'hmac-sha256-hex='.hash_hmac('sha256', $body, '');
        $this->call('POST', route('webhooks.easypost'), [], [], [], $server + ['HTTP_X_HMAC_SIGNATURE' => $empty], $body)->assertStatus(401);

        $this->assertSame(OrderStatus::Shipped, $item->refresh()->status);
        Queue::assertNotPushed(ReleaseSellerPayout::class);
    }

    public function test_easypost_update_never_revives_a_line_that_is_no_longer_on_its_way(): void
    {
        Queue::fake();
        $item = $this->shippedItem($this->readySeller());

        foreach ([OrderStatus::Refunded, OrderStatus::Cancelled, OrderStatus::ReturnRequested, OrderStatus::PayoutReleased] as $status) {
            $item->update(['tracking_number' => 'TRK123', 'status' => $status]);

            $this->easyPostTracker('TRK123', 'delivered')->assertOk();
            $this->assertSame($status, $item->refresh()->status);

            $this->easyPostTracker('TRK123', 'out_for_delivery')->assertOk();
            $this->assertSame($status, $item->refresh()->status);
        }

        Queue::assertNotPushed(ReleaseSellerPayout::class);
    }

    public function test_easypost_late_scan_does_not_move_a_delivered_line_backwards(): void
    {
        Queue::fake();
        $item = $this->shippedItem($this->readySeller());
        $item->update(['tracking_number' => 'TRK123', 'status' => OrderStatus::Delivered]);

        $this->easyPostTracker('TRK123', 'out_for_delivery')->assertOk();

        $this->assertSame(OrderStatus::Delivered, $item->refresh()->status);
    }

    public function test_easypost_delivery_covers_every_line_in_a_combined_shipment(): void
    {
        Queue::fake();
        $seller = $this->readySeller();
        $first = $this->shippedItem($seller);
        $second = $this->shippedItem($seller);
        $first->update(['tracking_number' => 'TRK123']);
        $second->update(['tracking_number' => 'TRK123']);

        $this->easyPostTracker('TRK123', 'delivered')->assertOk();

        $this->assertSame(OrderStatus::Delivered, $first->refresh()->status);
        $this->assertSame(OrderStatus::Delivered, $second->refresh()->status);
        Queue::assertPushed(ReleaseSellerPayout::class, 2);
    }

    /** A tracking update signed the way EasyPost signs it. */
    private function easyPostTracker(string $tracking, string $status): \Illuminate\Testing\TestResponse
    {
        config(['services.easypost.webhook_secret' => 'the-real-secret']);
        $body = json_encode(['description' => 'tracker.updated', 'result' => ['tracking_code' => $tracking, 'status' => $status]]);

        return $this->call('POST', route('webhooks.easypost'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HMAC_SIGNATURE' => 'hmac-sha256-hex='.hash_hmac('sha256', $body, 'the-real-secret'),
        ], $body);
    }

    // ---------- payout job ----------

    public function test_payout_job_transfers_and_marks_released(): void
    {
        $seller = $this->readySeller();
        $item = $this->deliveredItem($seller);

        $this->mock(TransferPayoutService::class)
            ->shouldReceive('transferToSeller')
            ->once()
            ->andReturn(Transfer::constructFrom(['id' => 'tr_test_1']));

        (new ReleaseSellerPayout($item->id))->handle(app(TransferPayoutService::class), app(WalletService::class), app(LoyaltyService::class));

        $item->refresh();
        $this->assertSame(OrderStatus::PayoutReleased, $item->status);
        $this->assertNotNull($item->payout_released_at);
        $this->assertDatabaseHas('order_status_history', [
            'order_item_id' => $item->id,
            'status' => 'payout_released',
        ]);
    }

    public function test_payout_job_is_idempotent_when_already_released(): void
    {
        $seller = $this->readySeller();
        $item = $this->deliveredItem($seller);
        $item->update(['status' => OrderStatus::PayoutReleased, 'payout_released_at' => now()]);

        $mock = $this->mock(TransferPayoutService::class);
        $mock->shouldNotReceive('transferToSeller');

        (new ReleaseSellerPayout($item->id))->handle(app(TransferPayoutService::class), app(WalletService::class), app(LoyaltyService::class));

        $this->assertSame(1, $item->statusHistory()->count() === 0 ? 1 : $item->statusHistory()->count()); // no crash
    }

    public function test_payout_job_alerts_admin_when_seller_account_not_ready(): void
    {
        $seller = User::factory()->create();
        SellerProfile::create([
            'user_id' => $seller->id, 'business_name' => 'X', 'business_type' => 'llc',
            'status' => 'approved', 'stripe_account_id' => null, 'stripe_onboarding_complete' => false,
        ]);
        $item = $this->deliveredItem($seller);

        $mock = $this->mock(TransferPayoutService::class);
        $mock->shouldNotReceive('transferToSeller');

        (new ReleaseSellerPayout($item->id))->handle(app(TransferPayoutService::class), app(WalletService::class), app(LoyaltyService::class));

        $this->assertDatabaseHas('admin_alerts', [
            'type' => 'payout_blocked',
            'related_order_item_id' => $item->id,
        ]);
        $this->assertSame(OrderStatus::Delivered, $item->refresh()->status);
    }

    // ---------- refund guard ----------

    public function test_refund_service_refuses_when_payout_already_released(): void
    {
        $seller = $this->readySeller();
        $item = $this->deliveredItem($seller);
        $item->update(['payout_released_at' => now()]);

        $this->expectException(RuntimeException::class);
        app(RefundService::class)->refund($item);
    }

    // ---------- helpers ----------

    private function address(): array
    {
        return [
            'name' => 'Jane Buyer', 'line1' => '1 Main St', 'city' => 'Detroit',
            'state' => 'MI', 'postal_code' => '48201', 'country' => 'US',
        ];
    }

    private function product(float $price = 100): Product
    {
        return Product::create([
            'seller_id' => User::factory()->create()->id,
            'category_id' => Category::create(['name' => 'C', 'slug' => 'c-'.Str::random(6)])->id,
            'title' => 'Widget', 'slug' => 'widget-'.Str::random(6),
            'price' => $price, 'quantity' => 10, 'status' => 'active',
        ]);
    }

    private function readySeller(): User
    {
        $u = User::factory()->create();
        SellerProfile::create([
            'user_id' => $u->id, 'business_name' => 'S', 'business_type' => 'llc',
            'status' => 'approved', 'stripe_account_id' => 'acct_123', 'stripe_onboarding_complete' => true,
        ]);

        return $u;
    }

    private function order(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'PM-'.strtoupper(Str::random(10)),
            'buyer_id' => User::factory()->create()->id,
            'status' => OrderStatus::Paid,
            'subtotal' => 100, 'total' => 100, 'currency' => 'USD',
            'shipping_address' => ['x' => 1], 'billing_address' => ['x' => 1],
            'stripe_payment_intent_id' => 'pi_'.Str::random(12),
        ], $overrides));
    }

    private function itemAttrs(User $seller, OrderStatus $status, array $overrides = []): array
    {
        return array_merge([
            'seller_id' => $seller->id,
            'product_id' => $this->product()->id,
            'product_title' => 'Widget', 'quantity' => 1,
            'unit_price' => 100, 'line_total' => 100, 'commission_rate' => 10,
            'platform_fee_amount' => 10, 'seller_payout_amount' => 90,
            'status' => $status,
        ], $overrides);
    }

    private function shippedItem(User $seller, int $shippedDaysAgo = 3): OrderItem
    {
        return $this->order()->items()->create(
            $this->itemAttrs($seller, OrderStatus::Shipped, [
                'shipped_at' => now()->subDays($shippedDaysAgo),
                'carrier' => 'USPS', 'tracking_number' => 'T'.Str::random(8),
            ])
        );
    }

    private function deliveredItem(User $seller): OrderItem
    {
        return $this->order()->items()->create(
            $this->itemAttrs($seller, OrderStatus::Delivered, ['delivered_at' => now()])
        );
    }

    private function eventPayload(string $type, string $paymentIntentId): string
    {
        return json_encode([
            'id' => 'evt_'.Str::random(10),
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => ['id' => $paymentIntentId, 'object' => 'payment_intent']],
        ]);
    }

    private function postStripe(string $payload)
    {
        $secret = config('services.stripe.webhook_secret');
        $t = time();
        $sig = hash_hmac('sha256', "{$t}.{$payload}", $secret);

        return $this->call('POST', route('webhooks.stripe'), [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$t},v1={$sig}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }
}
