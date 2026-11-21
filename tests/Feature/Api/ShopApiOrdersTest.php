<?php

namespace Tests\Feature\Api;

use App\Enums\OrderStatus;
use App\Jobs\ReleaseSellerPayout;
use App\Jobs\SendAppPush;
use App\Models\AppPushToken;
use App\Models\Category;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Models\SellerProfile;
use App\Models\User;
use App\Notifications\PriceDropAlert;
use App\Services\Payments\CheckoutPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Stripe\PaymentIntent;
use Tests\TestCase;

/** The shopping app's cart, checkout, orders, account features and push notifications. */
class ShopApiOrdersTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE = 'orders-device-0000-1111';

    private ?User $seller = null;

    private function seller(): User
    {
        if ($this->seller) {
            return $this->seller;
        }
        $u = User::factory()->create();
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'Hirev Sports', 'business_type' => 'llc', 'status' => 'approved', 'slug' => 'hirev-sports']);

        return $this->seller = $u;
    }

    private function product(array $extra = []): Product
    {
        return Product::create(array_merge([
            'seller_id' => $this->seller()->id,
            'category_id' => Category::firstOrCreate(['slug' => 'other-parts'], ['name' => 'Other Parts'])->id,
            'title' => 'Brake Pads '.Str::random(4), 'slug' => 'p-'.Str::random(10),
            'price' => 50, 'quantity' => 5, 'status' => 'active', 'condition' => 'new', 'weight_lbs' => 2,
        ], $extra))->fresh();
    }

    /** Each call starts clean: withHeaders() otherwise keeps headers from earlier requests in the test. */
    private function app_(array $headers = []): self
    {
        $this->flushHeaders();

        return $this->withHeaders(['Accept' => 'application/json', 'X-Device-Id' => self::DEVICE] + $headers);
    }

    private function address(array $extra = []): array
    {
        return array_merge([
            'name' => 'Sam Driver', 'line1' => '1 Main St', 'city' => 'Austin', 'state' => 'TX',
            'postal_code' => '78701', 'country' => 'US', 'phone' => '(512) 555-0100',
        ], $extra);
    }

    private function fakeStripe(): void
    {
        $this->mock(CheckoutPaymentService::class, function ($m) {
            $m->shouldReceive('createPaymentIntent')->andReturn(PaymentIntent::constructFrom(['id' => 'pi_'.Str::random(8), 'client_secret' => 'pi_secret_123']));
            $m->shouldReceive('intentFor')->andReturn(PaymentIntent::constructFrom(['id' => 'pi_again', 'client_secret' => 'pi_secret_again']));
        });
    }

    public function test_guest_cart_and_checkout_return_the_payment_sheet_details(): void
    {
        $this->fakeStripe();
        $p = $this->product();

        $this->app_()->postJson('/api/shop/v1/cart/items', ['product_id' => $p->id, 'quantity' => 2])->assertOk()
            ->assertJsonPath('count', 2)->assertJsonPath('groups.0.seller.name', 'Hirev Sports')->assertJsonPath('subtotal', 100);
        $this->app_()->patchJson('/api/shop/v1/cart/items', ['product_id' => $p->id, 'quantity' => 50])->assertOk()
            ->assertJsonPath('count', 5); // never more than in stock

        $this->app_()->postJson('/api/shop/v1/checkout', $this->address())->assertStatus(422)->assertJsonValidationErrors('email');

        $res = $this->app_()->postJson('/api/shop/v1/checkout', $this->address(['email' => 'guest@example.com']))->assertCreated();
        $res->assertJsonPath('payment.client_secret', 'pi_secret_123')->assertJsonPath('order.status', 'placed');
        $number = $res->json('order.number');
        $access = $res->json('order.access');

        $order = Order::where('order_number', $number)->firstOrFail();
        $this->assertSame('guest@example.com', $order->guest_email);
        $this->assertSame('+15125550100', $order->shipping_address['phone']);
        $this->assertSame(0, (int) $p->fresh()->quantity); // stock taken
        $this->app_()->getJson('/api/shop/v1/cart')->assertJsonPath('count', 0);

        // The guest can only see it with the access key.
        $this->app_()->getJson("/api/shop/v1/orders/{$number}/status")->assertNotFound();
        $this->app_(['X-Order-Access' => $access])->getJson("/api/shop/v1/orders/{$number}/status")->assertOk()->assertJsonPath('paid', false);
        $this->app_(['X-Order-Access' => $access])->postJson("/api/shop/v1/orders/{$number}/pay")->assertOk()->assertJsonPath('payment.client_secret', 'pi_secret_again');
    }

    public function test_checkout_is_refused_while_the_admin_has_paused_it(): void
    {
        $this->fakeStripe();
        $p = $this->product();
        $this->app_()->postJson('/api/shop/v1/cart/items', ['product_id' => $p->id]);

        config(['marketplace.platform.checkout_enabled' => false]);
        $this->app_()->postJson('/api/shop/v1/checkout', $this->address(['email' => 'g@example.com']))->assertStatus(503);
    }

    public function test_store_credit_pays_up_to_half_and_the_rest_goes_to_the_card(): void
    {
        $this->fakeStripe();
        $p = $this->product(['price' => 10]);
        // A Plus member ships free, so the sums are easy to read.
        $buyer = User::factory()->create(['wallet_balance' => 500, 'plus_until' => now()->addMonth()]);
        $auth = ['Authorization' => 'Bearer '.$buyer->createToken('phone')->plainTextToken];

        $this->app_($auth)->postJson('/api/shop/v1/cart/items', ['product_id' => $p->id])->assertOk()->assertJsonPath('credits_available', 5);
        $res = $this->app_($auth)->postJson('/api/shop/v1/checkout', $this->address(['use_credits' => true]))->assertCreated();

        $order = Order::where('order_number', $res->json('order.number'))->first();
        $this->assertSame(5.0, (float) $order->credits_used);
        $this->assertSame(5.0, (float) $order->total);
        $this->assertSame('pi_secret_123', $res->json('payment.client_secret'));
        $this->assertSame(495.0, (float) $buyer->fresh()->wallet_balance);
    }

    public function test_orders_list_detail_confirm_delivery_and_return(): void
    {
        Queue::fake();
        $p = $this->product();
        $buyer = User::factory()->create();
        $token = $buyer->createToken('phone')->plainTextToken;
        $auth = ['Authorization' => 'Bearer '.$token];

        $order = Order::create([
            'order_number' => 'PM-TEST'.Str::random(4), 'buyer_id' => $buyer->id, 'status' => OrderStatus::Paid,
            'subtotal' => 50, 'total' => 50, 'currency' => 'USD', 'shipping_address' => ['line1' => '1 A St'], 'billing_address' => ['line1' => '1 A St'],
        ]);
        $item = $order->items()->create([
            'seller_id' => $p->seller_id, 'product_id' => $p->id, 'product_title' => $p->title, 'product_sku' => 'S1', 'quantity' => 1,
            'unit_price' => 50, 'line_total' => 50, 'commission_rate' => 10, 'platform_fee_amount' => 5, 'seller_payout_amount' => 45,
            'status' => OrderStatus::Shipped, 'carrier' => 'USPS', 'tracking_number' => '9400 1000 0000',
        ]);

        $this->app_($auth)->getJson('/api/shop/v1/orders')->assertOk()->assertJsonPath('orders.0.number', $order->order_number)->assertJsonPath('orders.0.progress', 3);
        $this->app_($auth)->getJson("/api/shop/v1/orders/{$order->order_number}")->assertOk()
            ->assertJsonPath('order.items.0.tracking.carrier', 'USPS')->assertJsonPath('order.items.0.can.confirm_delivery', true);

        // Someone else can't see it.
        $other = User::factory()->create()->createToken('x')->plainTextToken;
        $this->app_(['Authorization' => 'Bearer '.$other])->getJson("/api/shop/v1/orders/{$order->order_number}")->assertNotFound();
        $this->app_(['Authorization' => 'Bearer '.$other])->postJson("/api/shop/v1/order-items/{$item->id}/confirm-delivery")->assertForbidden();

        $this->app_($auth)->postJson("/api/shop/v1/order-items/{$item->id}/return", ['reason' => 'Wrong part received'])->assertStatus(422);
        $this->app_($auth)->postJson("/api/shop/v1/order-items/{$item->id}/confirm-delivery")->assertOk();
        $this->assertSame(OrderStatus::Delivered, $item->fresh()->status);
        Queue::assertPushed(ReleaseSellerPayout::class);

        $this->app_($auth)->postJson("/api/shop/v1/order-items/{$item->id}/return", ['reason' => 'Wrong part received'])->assertOk();
        $this->assertSame(1, ReturnRequest::count());
        $this->app_($auth)->getJson("/api/shop/v1/orders/{$order->order_number}")->assertJsonPath('order.items.0.return.status', 'requested')
            ->assertJsonPath('order.items.0.return.can_escalate', true);
    }

    public function test_messages_with_a_seller(): void
    {
        $p = $this->product();
        $buyer = User::factory()->create();
        $auth = ['Authorization' => 'Bearer '.$buyer->createToken('phone')->plainTextToken];

        $id = $this->app_($auth)->postJson("/api/shop/v1/products/{$p->id}/message-seller", ['body' => 'Is this in stock?'])->assertCreated()->json('conversation_id');
        $this->app_($auth)->postJson("/api/shop/v1/messages/{$id}", ['body' => 'Also, ships to Texas?'])->assertCreated();
        $this->app_($auth)->getJson("/api/shop/v1/messages/{$id}")->assertOk()->assertJsonCount(2, 'conversation.messages')->assertJsonPath('conversation.messages.0.mine', true);
        $this->app_($auth)->getJson('/api/shop/v1/messages')->assertOk()->assertJsonPath('conversations.0.seller.name', 'Hirev Sports');
        $this->assertSame(1, Conversation::count());
    }

    public function test_compare_and_plus_work_for_guests_and_preferences_for_members(): void
    {
        $a = $this->product();
        $b = $this->product();
        $this->app_()->getJson("/api/shop/v1/compare?ids={$a->id},{$b->id}")->assertOk()->assertJsonCount(2, 'products')->assertJsonPath('max', 4);
        $this->app_()->getJson('/api/shop/v1/plus')->assertOk()->assertJsonPath('active', false);

        $buyer = User::factory()->create();
        $auth = ['Authorization' => 'Bearer '.$buyer->createToken('phone')->plainTextToken];
        $prefs = $this->app_($auth)->getJson('/api/shop/v1/preferences')->assertOk();
        $key = $prefs->json('emails.0.key');
        $this->app_($auth)->patchJson('/api/shop/v1/preferences', ['email' => $key, 'on' => false])->assertOk();
        $this->assertFalse(collect($this->app_($auth)->getJson('/api/shop/v1/preferences')->json('emails'))->firstWhere('key', $key)['on']);
    }

    public function test_push_tokens_and_a_price_drop_reaches_the_phone(): void
    {
        $buyer = User::factory()->create();
        $auth = ['Authorization' => 'Bearer '.$buyer->createToken('phone')->plainTextToken];
        $this->app_($auth)->postJson('/api/shop/v1/push-tokens', ['token' => 'not-a-token'])->assertStatus(422);
        $this->app_($auth)->postJson('/api/shop/v1/push-tokens', ['token' => 'ExponentPushToken[abc123]', 'platform' => 'ios'])->assertOk();
        $this->assertSame(1, AppPushToken::count());

        Bus::fake([SendAppPush::class]);
        $p = $this->product(['title' => 'Carbon Wing']);
        $buyer->notify(new PriceDropAlert($p, 120.0, 99.0));
        Bus::assertDispatched(SendAppPush::class, fn ($job) => $job->userId === $buyer->id
            && str_contains($job->title, 'Carbon Wing') && $job->data['link']['screen'] === 'product');

        // Sending: Expo is called; a phone that uninstalled the app is forgotten.
        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']]]])]);
        (new SendAppPush($buyer->id, 'Hi', 'There'))->handle();
        $this->assertSame(0, AppPushToken::count());
    }

    public function test_app_link_files_stay_off_until_configured(): void
    {
        $this->get('/.well-known/apple-app-site-association')->assertNotFound();
        config(['services.apple.team_id' => 'TEAM123']);
        $this->getJson('/.well-known/apple-app-site-association')->assertOk()
            ->assertJsonPath('applinks.details.0.appIDs.0', 'TEAM123.com.panmotive.app');
    }
}
