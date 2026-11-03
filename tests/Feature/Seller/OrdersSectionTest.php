<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Livewire\Seller\Offers;
use App\Livewire\Storefront\MakeOffer;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Models\SellerProfile;
use App\Models\StockMovement;
use App\Models\User;
use App\Notifications\ReturnUpdated;
use App\Services\Marketing\CouponService;
use App\Services\Orders\OrderStockService;
use App\Services\Orders\UnpaidOrderService;
use App\Services\Payments\CheckoutPaymentService;
use App\Services\Payments\PayoutReversalService;
use App\Services\Payments\RefundService;
use App\Services\Seller\OfferService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Stripe\PaymentIntent;
use Tests\TestCase;

class OrdersSectionTest extends TestCase
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
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'Shop '.uniqid(), 'business_type' => 'llc', 'status' => 'approved', 'slug' => 's-'.uniqid()]);

        return $this->withTwoFactor($u);
    }

    private function product(User $seller, float $price = 100, array $extra = []): Product
    {
        return Product::create(array_merge([
            'seller_id' => $seller->id,
            'category_id' => Category::create(['name' => 'C', 'slug' => 'c-'.Str::random(6)])->id,
            'title' => 'Part '.Str::random(5), 'slug' => 'p-'.Str::random(8),
            'price' => $price, 'quantity' => 10, 'status' => 'active',
        ], $extra))->fresh();
    }

    private function item(User $seller, User $buyer, OrderStatus $status, array $item = [], array $order = []): OrderItem
    {
        $product = $item['product'] ?? $this->product($seller);
        unset($item['product']);
        $o = Order::create(array_merge([
            'order_number' => 'PM-'.strtoupper(Str::random(10)), 'buyer_id' => $buyer->id,
            'status' => $status === OrderStatus::Placed ? OrderStatus::Placed : OrderStatus::Paid,
            'subtotal' => 100, 'shipping_total' => 0, 'discount_total' => 0, 'credits_used' => 0, 'total' => 100,
            'stripe_payment_intent_id' => 'pi_'.Str::random(8),
            'shipping_address' => ['line1' => '1 St'], 'billing_address' => ['line1' => '1 St'], 'placed_at' => now(),
        ], $order));

        return $o->items()->create(array_merge([
            'seller_id' => $seller->id, 'product_id' => $product->id, 'product_title' => $product->title,
            'quantity' => 1, 'unit_price' => 100, 'line_total' => 100, 'commission_rate' => 10,
            'platform_fee_amount' => 10, 'seller_payout_amount' => 90, 'status' => $status,
        ], $item));
    }

    private function rma(OrderItem $item, string $status = 'requested'): ReturnRequest
    {
        return ReturnRequest::create(['order_item_id' => $item->id, 'user_id' => $item->order->buyer_id, 'reason' => 'Wrong part', 'status' => $status]);
    }

    // ================= returns =================

    public function test_refund_actually_refunds_the_buyer(): void
    {
        Notification::fake();
        $this->mock(RefundService::class)->shouldReceive('refundAmount')->once()->withArgs(fn ($o, $amount) => abs($amount - 100) < 0.01);

        $seller = $this->seller();
        $buyer = User::factory()->create();
        $item = $this->item($seller, $buyer, OrderStatus::ReturnApproved, ['delivered_at' => now()]);
        $rma = $this->rma($item, 'received');

        $this->actingAs($seller)->post(route('seller.returns.update', $rma), ['decision' => 'refund'])
            ->assertSessionHas('success', '$100.00 refunded to the buyer.');

        $this->assertSame('refunded', $rma->fresh()->status);
        $this->assertSame(OrderStatus::Refunded, $item->fresh()->status);
        Notification::assertSentTo($buyer, ReturnUpdated::class, fn ($n) => $n->step === 'refunded');
    }

    public function test_refund_after_payout_claws_back_the_payout_and_cashback(): void
    {
        Notification::fake();
        $this->mock(RefundService::class)->shouldReceive('refundAmount')->once();
        $this->mock(PayoutReversalService::class)->shouldReceive('reverseTransfer')->once();

        $seller = $this->seller();
        $buyer = User::factory()->create(['wallet_balance' => 10, 'lifetime_spend' => 500]);
        $item = $this->item($seller, $buyer, OrderStatus::ReturnApproved, [
            'payout_released_at' => now()->subDay(), 'cashback_amount' => 3, 'cashback_credited_at' => now()->subDay(),
        ]);

        $this->actingAs($seller)->post(route('seller.returns.update', $this->rma($item, 'received')), ['decision' => 'refund'])->assertSessionHasNoErrors();

        $this->assertEquals(7, (float) $buyer->fresh()->wallet_balance);   // $3 cashback taken back
        $this->assertEquals(400, (float) $buyer->fresh()->lifetime_spend);
        $this->assertNull($item->fresh()->cashback_credited_at);
    }

    public function test_return_steps_are_enforced_and_declining_needs_a_reason(): void
    {
        $seller = $this->seller();
        $item = $this->item($seller, User::factory()->create(), OrderStatus::ReturnRequested);
        $rma = $this->rma($item);

        $this->actingAs($seller)->post(route('seller.returns.update', $rma), ['decision' => 'receive'])->assertSessionHasErrors('return');
        $this->actingAs($seller)->post(route('seller.returns.update', $rma), ['decision' => 'reject'])->assertSessionHasErrors('return');
        $this->assertSame('requested', $rma->fresh()->status);

        Notification::fake();
        $this->actingAs($seller)->post(route('seller.returns.update', $rma), ['decision' => 'reject', 'note' => 'Part was installed'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $rma->fresh()->status);
        $this->assertSame(OrderStatus::Delivered, $item->fresh()->status);
    }

    public function test_receiving_a_return_restocks_the_right_option(): void
    {
        Notification::fake();
        $seller = $this->seller();
        $p = $this->product($seller);
        $blue = $p->variants()->create(['label' => 'Blue', 'options' => ['Color' => 'Blue'], 'quantity' => 2]);
        $item = $this->item($seller, User::factory()->create(), OrderStatus::ReturnApproved, ['product' => $p, 'product_variant_id' => $blue->id, 'quantity' => 1]);

        $this->actingAs($seller)->post(route('seller.returns.update', $this->rma($item, 'approved')), ['decision' => 'receive', 'restock' => 1])->assertSessionHasNoErrors();

        $this->assertSame(3, $blue->fresh()->quantity);
        $this->assertSame('return', StockMovement::latest('id')->value('reason'));
    }

    public function test_refund_without_return_skips_shipping_it_back(): void
    {
        Notification::fake();
        $this->mock(RefundService::class)->shouldReceive('refundAmount')->once();
        $seller = $this->seller();
        $item = $this->item($seller, User::factory()->create(), OrderStatus::ReturnRequested);

        $this->actingAs($seller)->post(route('seller.returns.update', $this->rma($item)), ['decision' => 'refund_without_return'])->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::Refunded, $item->fresh()->status);
    }

    public function test_returns_close_after_the_return_window(): void
    {
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $old = $this->item($seller, $buyer, OrderStatus::Delivered, ['delivered_at' => now()->subDays(31)]);
        $fresh = $this->item($seller, $buyer, OrderStatus::Delivered, ['delivered_at' => now()->subDays(5)]);

        $this->actingAs($buyer)->post(route('orders.return', $old), ['reason' => 'Changed mind'])->assertStatus(422);
        $this->actingAs($buyer)->post(route('orders.return', $fresh), ['reason' => 'Wrong part'])->assertRedirect();
        $this->assertSame(1, ReturnRequest::count());
    }

    public function test_returns_page_shows_insights(): void
    {
        $seller = $this->seller();
        $this->rma($this->item($seller, User::factory()->create(), OrderStatus::ReturnRequested));
        $this->actingAs($seller)->get(route('seller.returns.index'))->assertOk()->assertSee('Return rate')->assertSee('Wrong part');
    }

    // ================= unpaid orders =================

    public function test_unpaid_orders_expire_and_free_their_stock(): void
    {
        $this->mock(CheckoutPaymentService::class)->shouldReceive('cancelIntent')->once();
        $seller = $this->seller();
        $p = $this->product($seller);
        $item = $this->item($seller, User::factory()->create(), OrderStatus::Placed, ['product' => $p, 'quantity' => 3]);
        app(OrderStockService::class)->commit($item->order);
        $this->assertSame(7, $p->fresh()->quantity);

        $this->travel(49)->hours();
        $this->assertSame(1, app(UnpaidOrderService::class)->expireStale());

        $this->assertSame(OrderStatus::Cancelled, $item->order->fresh()->status);
        $this->assertSame('unpaid', $item->fresh()->cancel_reason);
        $this->assertSame(10, $p->fresh()->quantity);
    }

    public function test_a_declined_card_leaves_the_order_open_for_retry(): void
    {
        config()->set('services.stripe.webhook_secret', 'whsec_test');
        $seller = $this->seller();
        $item = $this->item($seller, User::factory()->create(), OrderStatus::Placed);

        $payload = json_encode(['id' => 'evt_1', 'object' => 'event', 'type' => 'payment_intent.payment_failed',
            'data' => ['object' => ['id' => $item->order->stripe_payment_intent_id, 'object' => 'payment_intent']]]);
        $t = time();
        $this->call('POST', route('webhooks.stripe'), [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$payload}", 'whsec_test'), 'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertOk();

        $this->assertSame(OrderStatus::Placed, $item->order->fresh()->status);
    }

    public function test_buyer_can_pay_an_unpaid_order(): void
    {
        $this->mock(CheckoutPaymentService::class)->shouldReceive('intentFor')->once()
            ->andReturn(PaymentIntent::constructFrom(['id' => 'pi_x', 'client_secret' => 'pi_x_secret']));
        $buyer = User::factory()->create();
        $item = $this->item($this->seller(), $buyer, OrderStatus::Placed);

        $this->actingAs($buyer)->get(route('orders.show', $item->order))->assertSee('Pay $100.00 now');
        $this->actingAs($buyer)->get(route('orders.pay', $item->order))->assertOk()->assertViewIs('storefront.checkout.pay');
        $this->actingAs(User::factory()->create())->get(route('orders.pay', $item->order))->assertForbidden();
    }

    // ================= offers =================

    public function test_buyer_accepts_a_counter_and_buys_now_with_a_locked_code(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller, 100, ['accepts_offers' => true]);
        $buyer = User::factory()->create();
        $svc = app(OfferService::class);
        $offer = $svc->create($buyer, $product, 70, 2);
        $svc->counter($offer->fresh(), 85);

        Livewire::actingAs($buyer)->test(MakeOffer::class, ['product' => $product])
            ->assertSee('Accept $85.00')
            ->call('acceptCounter')
            ->call('buyNow')->assertRedirect(route('checkout.index'));

        $coupon = Coupon::where('code', $offer->fresh()->coupon_code)->firstOrFail();
        $this->assertSame($buyer->id, $coupon->user_id);
        $this->assertSame($product->id, $coupon->product_id);
        $this->assertSame(2, $coupon->min_quantity);
        $this->assertEquals(30, (float) $coupon->value); // ($100 − $85) × 2
        $this->assertSame(2, Cart::where('user_id', $buyer->id)->first()->items()->sum('quantity'));
    }

    public function test_offer_codes_only_work_for_that_buyer_listing_and_quantity(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller, 100, ['accepts_offers' => true]);
        $other = $this->product($seller, 100);
        $buyer = User::factory()->create();
        $offer = app(OfferService::class)->create($buyer, $product, 80, 2);
        app(OfferService::class)->accept($offer->fresh(), 'seller');
        $code = $offer->fresh()->coupon_code;
        $coupons = app(CouponService::class);
        $line = fn ($p, $q) => ['product' => $p, 'quantity' => $q, 'line_total' => 100 * $q];

        $this->assertFalse($coupons->validate($code, User::factory()->create(), 200, [$line($product, 2)])['valid']); // someone else
        $this->assertFalse($coupons->validate($code, $buyer, 100, [$line($product, 1)])['valid']);                    // too few
        $this->assertFalse($coupons->validate($code, $buyer, 200, [$line($other, 2)])['valid']);                      // other listing
        $this->assertTrue($coupons->validate($code, $buyer, 300, [$line($product, 2), $line($other, 1)])['valid']);
    }

    public function test_expired_offers_cannot_be_accepted_and_errors_show(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller, 100, ['accepts_offers' => true, 'min_offer_price' => 60]);
        $buyer = User::factory()->create();
        $offer = app(OfferService::class)->create($buyer, $product, 70, 1);
        $offer->update(['expires_at' => now()->subMinute()]);

        Livewire::actingAs($seller)->test(Offers::class)->call('accept', $offer->id);
        $this->assertSame('expired', $offer->fresh()->status);

        Livewire::actingAs(User::factory()->create())->test(MakeOffer::class, ['product' => $product])
            ->set('price', 50)->call('submit')->assertHasErrors('price');

        $fresh = app(OfferService::class)->create(User::factory()->create(), $product, 70, 1);
        Livewire::actingAs($seller)->test(Offers::class)
            ->set("counter.{$fresh->id}", 65)->call('sendCounter', $fresh->id)->assertHasErrors("counter.{$fresh->id}");
    }

    public function test_offers_at_the_auto_accept_price_are_accepted_instantly(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller, 100, ['accepts_offers' => true, 'auto_accept_offer_price' => 90]);

        $low = app(OfferService::class)->create(User::factory()->create(), $product, 80, 1);
        $high = app(OfferService::class)->create(User::factory()->create(), $product, 92, 1);

        $this->assertSame('pending', $low->fresh()->status);
        $this->assertSame('accepted', $high->fresh()->status);
        $this->assertNotNull($high->fresh()->coupon_code);
    }

    // ================= orders =================

    public function test_late_shipments_are_flagged_and_bulk_accept_works(): void
    {
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $late = $this->item($seller, $buyer, OrderStatus::SellerAccepted, order: ['placed_at' => now()->subDays(10)]);
        $a = $this->item($seller, $buyer, OrderStatus::Paid);
        $b = $this->item($seller, $buyer, OrderStatus::Paid);

        $this->assertTrue($late->fresh()->isLate());
        $this->actingAs($seller)->get(route('seller.orders.index', ['status' => 'late']))->assertOk()->assertSee($late->order->order_number)->assertDontSee($a->order->order_number);
        $this->actingAs($seller)->get(route('seller.dashboard'))->assertSee('late to ship');

        $this->actingAs($seller)->post(route('seller.orders.bulk-accept'), ['ids' => [$a->id, $b->id, $late->id]])->assertSessionHas('success');
        $this->assertSame(OrderStatus::SellerAccepted, $a->fresh()->status);
        $this->assertSame(OrderStatus::SellerAccepted, $b->fresh()->status);
    }

    public function test_tracking_links_for_known_carriers(): void
    {
        $item = new OrderItem(['carrier' => 'UPS', 'tracking_number' => '1Z 999']);
        $this->assertSame('https://www.ups.com/track?tracknum=1Z999', $item->trackingUrl());
        $this->assertNull((new OrderItem(['carrier' => 'Local van', 'tracking_number' => 'X1']))->trackingUrl());
    }
}
