<?php

namespace Tests\Feature\Payments;

use App\Enums\OrderStatus;
use App\Models\AdminAlert;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Orders\BuyerRefundService;
use App\Services\Payments\CheckoutPaymentService;
use App\Services\Payments\RefundService;
use App\Services\Subscriptions\SubscriptionService;
use App\Services\Tax\SalesTaxService;
use App\Services\Tax\StripeTaxClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\PaymentIntent;
use Tests\TestCase;

class SalesTaxTest extends TestCase
{
    use RefreshDatabase;

    private function address(): array
    {
        return ['name' => 'Jane', 'line1' => '1 Main St', 'city' => 'Detroit', 'state' => 'Michigan', 'postal_code' => '48201', 'country' => 'US'];
    }

    private function product(float $price = 100): Product
    {
        return Product::create([
            'seller_id' => User::factory()->create()->id,
            'category_id' => Category::create(['name' => 'C'.uniqid(), 'slug' => 'c-'.Str::random(6)])->id,
            'title' => 'Widget', 'slug' => 'widget-'.Str::random(6),
            'price' => $price, 'quantity' => 10, 'status' => 'active',
        ]);
    }

    private function buyerWith(Product $product, int $quantity = 2): User
    {
        $buyer = User::factory()->create();
        Cart::create(['user_id' => $buyer->id])->items()->create(['product_id' => $product->id, 'quantity' => $quantity]);

        return $buyer;
    }

    private function expectPayment(?callable $check = null): void
    {
        $this->mock(CheckoutPaymentService::class)->shouldReceive('createPaymentIntent')->once()
            ->withArgs(fn (Order $order) => $check ? $check($order) : true)
            ->andReturn(PaymentIntent::constructFrom(['id' => 'pi_1', 'client_secret' => 's']));
    }

    public function test_with_collection_off_nothing_changes_and_stripe_tax_is_never_called(): void
    {
        config()->set('marketplace.sales_tax.collecting', false);
        $this->mock(StripeTaxClient::class)->shouldNotReceive('calculate');
        $this->expectPayment(fn (Order $o) => (float) $o->total === 200.0);

        $buyer = $this->buyerWith($this->product());
        $this->actingAs($buyer)->get(route('checkout.index'))->assertOk()->assertDontSee('Sales tax');
        $this->actingAs($buyer)->post(route('checkout.store'), $this->address())->assertOk();

        $order = Order::firstOrFail();
        $this->assertEquals(0, (float) $order->tax_total);
        $this->assertNull($order->tax_calculation_id);
        $this->assertEquals(0, (float) $order->items->first()->tax_amount);
    }

    public function test_tax_is_added_to_the_charge_but_never_to_the_seller_payout(): void
    {
        config()->set('marketplace.sales_tax.collecting', true);

        $sent = null;
        $this->mock(StripeTaxClient::class)->shouldReceive('calculate')->once()
            ->withArgs(function ($params) use (&$sent) {
                $sent = $params;

                return true;
            })
            ->andReturn(['id' => 'taxcalc_1', 'tax' => 1200, 'shipping_tax' => 0, 'lines' => ['line-0' => 1200]]);
        $this->expectPayment(fn (Order $o) => (float) $o->total === 212.0);

        $buyer = $this->buyerWith($this->product());
        $this->actingAs($buyer)->get(route('checkout.index'))->assertOk()->assertSee('Added on the next step');
        $this->actingAs($buyer)->post(route('checkout.store'), $this->address())
            ->assertOk()->assertSee('includes $12.00 sales tax');

        // The address went to Stripe with the state as its 2-letter code.
        $this->assertSame('MI', $sent['customer_details']['address']['state']);
        $this->assertSame('48201', $sent['customer_details']['address']['postal_code']);
        $this->assertSame('shipping', $sent['customer_details']['address_source']);
        $this->assertSame(20000, $sent['line_items'][0]['amount']);
        $this->assertSame('exclusive', $sent['line_items'][0]['tax_behavior']);
        $this->assertSame('txcd_99999999', $sent['line_items'][0]['tax_code']);

        $order = Order::firstOrFail();
        $this->assertEquals(12.00, (float) $order->tax_total);
        $this->assertEquals(212.00, (float) $order->total);
        $this->assertSame('taxcalc_1', $order->tax_calculation_id);

        $item = $order->items->first();
        $this->assertEquals(12.00, (float) $item->tax_amount);
        $this->assertEquals(200.00, (float) $item->line_total);
        $this->assertEquals(20.00, (float) $item->platform_fee_amount, 'No commission on the tax.');
        $this->assertEquals(180.00, (float) $item->seller_payout_amount, 'The tax is not the seller\'s money.');
    }

    public function test_shipping_is_sent_with_its_own_tax_code_and_its_tax_is_kept_separately(): void
    {
        config()->set('marketplace.sales_tax.collecting', true);

        $client = $this->mock(StripeTaxClient::class);
        $client->shouldReceive('calculate')->once()
            ->withArgs(fn ($p) => $p['shipping_cost'] === ['amount' => 1000, 'tax_behavior' => 'exclusive', 'tax_code' => 'txcd_92010001'])
            ->andReturn(['id' => 'taxcalc_2', 'tax' => 660, 'shipping_tax' => 60, 'lines' => ['line-0' => 600]]);

        $quote = app(SalesTaxService::class)->calculate([0 => 100.0], 10.0, $this->address() + ['state' => 'MI']);

        $this->assertEquals(6.60, $quote->total);
        $this->assertEquals(0.60, $quote->shipping);
        $this->assertEquals(6.00, $quote->byLine[0]);
    }

    public function test_a_fully_discounted_order_still_pays_tax_on_its_shipping(): void
    {
        config()->set('marketplace.sales_tax.collecting', true);

        $client = $this->mock(StripeTaxClient::class);
        $client->shouldReceive('calculate')->once()
            ->withArgs(fn ($p) => count($p['line_items']) === 1
                && $p['line_items'][0]['amount'] === 1000
                && $p['line_items'][0]['tax_code'] === 'txcd_92010001'
                && ! isset($p['shipping_cost']))
            ->andReturn(['id' => 'taxcalc_3', 'tax' => 60, 'shipping_tax' => 0, 'lines' => ['shipping' => 60]]);

        $quote = app(SalesTaxService::class)->calculate([0 => 0.0], 10.0, $this->address());

        $this->assertEquals(0.60, $quote->total);
        $this->assertEquals(0.60, $quote->shipping);
    }

    public function test_tax_is_charged_on_the_price_after_a_platform_coupon(): void
    {
        config()->set('marketplace.sales_tax.collecting', true);

        $coupon = Coupon::create(['code' => 'TAKE20', 'type' => 'fixed', 'value' => 20, 'is_active' => true]);
        $this->assertNull($coupon->seller_id ?? null);

        $this->mock(StripeTaxClient::class)->shouldReceive('calculate')->once()
            ->withArgs(fn ($p) => $p['line_items'][0]['amount'] === 18000)
            ->andReturn(['id' => 'taxcalc_4', 'tax' => 1080, 'shipping_tax' => 0, 'lines' => ['line-0' => 1080]]);
        $this->expectPayment();

        $buyer = $this->buyerWith($this->product());
        $this->actingAs($buyer)->withSession(['checkout.coupon_code' => 'TAKE20'])
            ->post(route('checkout.store'), $this->address())->assertOk();

        $this->assertEquals(190.80 - 0, (float) Order::firstOrFail()->total);
    }

    public function test_discount_shares_add_up_exactly_whatever_the_rounding(): void
    {
        $amounts = app(SalesTaxService::class)->taxableAmounts(['a' => 33.33, 'b' => 33.33, 'c' => 33.34], 10.0);

        $this->assertEquals(90.0, round(array_sum($amounts), 2));
        $this->assertEquals(['a' => 30.0, 'b' => 30.0, 'c' => 30.0], $amounts);

        $this->assertEquals(['a' => 0.0], app(SalesTaxService::class)->taxableAmounts(['a' => 10.0], 25.0), 'A discount bigger than the goods leaves nothing to tax.');
    }

    public function test_store_credit_does_not_lower_the_tax(): void
    {
        config()->set('marketplace.sales_tax.collecting', true);

        $this->mock(StripeTaxClient::class)->shouldReceive('calculate')->once()
            ->withArgs(fn ($p) => $p['line_items'][0]['amount'] === 20000)
            ->andReturn(['id' => 'taxcalc_5', 'tax' => 1200, 'shipping_tax' => 0, 'lines' => ['line-0' => 1200]]);
        $this->expectPayment();

        $buyer = $this->buyerWith($this->product());
        $buyer->forceFill(['wallet_balance' => 50])->save();

        $this->actingAs($buyer)->post(route('checkout.store'), $this->address() + ['use_credits' => 1])->assertOk();

        $order = Order::firstOrFail();
        $this->assertEquals(12.0, (float) $order->tax_total);
        $this->assertEquals(round(212 - (float) $order->credits_used, 2), (float) $order->total);
    }

    public function test_if_tax_cannot_be_worked_out_the_order_stops_and_the_cart_is_kept(): void
    {
        config()->set('marketplace.sales_tax.collecting', true);
        $this->mock(StripeTaxClient::class)->shouldReceive('calculate')->once()->andThrow(new \RuntimeException('customer_tax_location_invalid'));
        $this->mock(CheckoutPaymentService::class)->shouldNotReceive('createPaymentIntent');

        $buyer = $this->buyerWith($this->product());

        $this->actingAs($buyer)->from(route('checkout.index'))
            ->post(route('checkout.store'), $this->address())
            ->assertRedirect(route('checkout.index'))
            ->assertSessionHasErrors('postal_code');

        $this->assertSame(0, Order::count());
        $this->assertSame(1, Cart::where('user_id', $buyer->id)->first()->items()->count());
        $this->assertSame(10, Product::first()->quantity, 'No stock was taken.');
    }

    private function paidOrder(array $order = [], array $item = []): Order
    {
        $seller = User::factory()->create();
        $o = Order::create($order + [
            'order_number' => 'PM-'.strtoupper(uniqid()), 'buyer_id' => User::factory()->create()->id, 'status' => OrderStatus::Paid,
            'subtotal' => 100, 'shipping_total' => 10, 'tax_total' => 6.60, 'shipping_tax' => 0.60, 'total' => 116.60,
            'tax_calculation_id' => 'taxcalc_1', 'tax_transaction_id' => 'tax_1', 'stripe_payment_intent_id' => 'pi_1',
            'shipping_address' => ['line1' => 'x'], 'billing_address' => ['line1' => 'x'],
        ]);
        $o->items()->create($item + [
            'seller_id' => $seller->id, 'product_id' => $this->product()->id, 'product_title' => 'Part',
            'quantity' => 1, 'unit_price' => 100, 'line_total' => 100, 'tax_amount' => 6.00, 'commission_rate' => 10,
            'platform_fee_amount' => 10, 'seller_payout_amount' => 90, 'status' => OrderStatus::Paid,
        ]);

        return $o->fresh('items');
    }

    public function test_a_refund_gives_the_tax_back_and_tells_stripe_tax(): void
    {
        $order = $this->paidOrder();
        $item = $order->items->first();

        $this->mock(RefundService::class)->shouldReceive('refundAmount')->once()->withArgs(fn ($o, $amount) => abs($amount - 106.0) < 0.001);
        $this->mock(StripeTaxClient::class)->shouldReceive('reverse')->once()
            ->with('tax_1', "{$order->order_number}-refund-{$item->id}", 10600)->andReturn('tax_rev_1');

        $this->assertEquals(106.0, app(BuyerRefundService::class)->paidForLine($item));
        $this->assertEquals(106.0, app(BuyerRefundService::class)->refundLine($item, 'test', 'Refund'));
    }

    public function test_refunding_shipping_includes_the_tax_on_shipping(): void
    {
        $order = $this->paidOrder();

        $this->assertEquals(116.60, app(BuyerRefundService::class)->paidForLine($order->items->first(), includeShipping: true));
    }

    public function test_a_failed_tax_record_never_blocks_the_buyers_refund_but_alerts_staff(): void
    {
        $order = $this->paidOrder();

        $this->mock(RefundService::class)->shouldReceive('refundAmount')->once();
        $this->mock(StripeTaxClient::class)->shouldReceive('reverse')->andThrow(new \RuntimeException('Stripe down'));

        $this->assertEquals(106.0, app(BuyerRefundService::class)->refundLine($order->items->first(), 'test', 'Refund'));
        $this->assertSame(1, AdminAlert::where('type', 'sales_tax_refund_not_recorded')->count());
    }

    public function test_paying_records_the_sale_once(): void
    {
        config()->set('services.stripe.webhook_secret', 'whsec_test');
        $order = $this->paidOrder(['status' => OrderStatus::Placed, 'tax_transaction_id' => null], ['status' => OrderStatus::Placed]);

        $this->mock(StripeTaxClient::class)->shouldReceive('record')->once()->with('taxcalc_1', $order->order_number)->andReturn('tax_9');

        $payload = json_encode(['id' => 'evt_1', 'object' => 'event', 'type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_1', 'object' => 'payment_intent']]]);
        $t = time();
        $post = fn () => $this->call('POST', route('webhooks.stripe'), [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$payload}", 'whsec_test'),
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $post()->assertOk();
        $post()->assertOk();

        $this->assertSame('tax_9', $order->fresh()->tax_transaction_id);
    }

    public function test_a_failed_sale_record_raises_an_alert_and_the_order_stays_paid(): void
    {
        $order = $this->paidOrder(['tax_transaction_id' => null]);
        $this->mock(StripeTaxClient::class)->shouldReceive('record')->andThrow(new \RuntimeException('Stripe down'));

        app(SalesTaxService::class)->recordSale($order);

        $this->assertNull($order->fresh()->tax_transaction_id);
        $this->assertSame(1, AdminAlert::where('type', 'sales_tax_not_recorded')->count());
    }

    public function test_subscription_orders_are_taxed_and_wait_if_tax_is_unavailable(): void
    {
        config()->set('marketplace.sales_tax.collecting', true);

        $product = $this->product(50);
        $buyer = User::factory()->create();
        $sub = Subscription::create([
            'buyer_id' => $buyer->id, 'seller_id' => $product->seller_id, 'product_id' => $product->id, 'quantity' => 1, 'interval_days' => 30,
            'status' => 'active', 'next_run_at' => now(), 'shipping_address' => ['line1' => '1 Main', 'state' => 'MI', 'postal_code' => '48201', 'country' => 'US'],
        ]);

        $client = $this->mock(StripeTaxClient::class);
        $client->shouldReceive('calculate')->once()->andThrow(new \RuntimeException('down'));
        $this->assertNull(app(SubscriptionService::class)->placeOrder($sub->fresh()));
        $this->assertSame(0, Order::count());
        $this->assertSame(1, AdminAlert::where('type', 'subscription_tax_failed')->count());
        $this->assertTrue($sub->fresh()->next_run_at->isFuture());

        $client->shouldReceive('calculate')->once()->andReturn(['id' => 'taxcalc_s', 'tax' => 300, 'shipping_tax' => 0, 'lines' => ['line-0' => 300]]);
        $order = app(SubscriptionService::class)->placeOrder($sub->fresh());

        $this->assertEquals(3.00, (float) $order->tax_total);
        $this->assertEquals(3.00, (float) $order->items->first()->tax_amount);
    }

    public function test_receipts_show_the_tax(): void
    {
        $order = $this->paidOrder();

        $this->actingAs($order->buyer)->get(route('orders.show', $order))->assertOk()->assertSee('Sales tax')->assertSee('$6.60');
        $this->actingAs($order->buyer)->get(route('checkout.confirmation', $order->order_number))->assertOk()->assertSee('Sales tax')->assertSee('$6.60');
    }

    public function test_real_stripe_requests_use_the_right_addresses_and_fields(): void
    {
        config()->set('services.stripe.secret', 'sk_test_fake');

        $http = new class implements ClientInterface {
            public array $requests = [];

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->requests[] = compact('method', 'absUrl', 'params');

                $body = match (true) {
                    str_ends_with($absUrl, '/tax/calculations') => ['id' => 'taxcalc_x', 'object' => 'tax.calculation', 'tax_amount_exclusive' => 660, 'shipping_cost' => ['amount' => 1000, 'amount_tax' => 60]],
                    str_contains($absUrl, '/line_items') => ['object' => 'list', 'has_more' => false, 'url' => '/v1/tax/calculations/taxcalc_x/line_items', 'data' => [['id' => 'tax_li_1', 'object' => 'tax.calculation_line_item', 'reference' => 'line-0', 'amount_tax' => 600]]],
                    default => ['id' => 'tax_x', 'object' => 'tax.transaction'],
                };

                return [json_encode($body), 200, []];
            }
        };

        ApiRequestor::setHttpClient($http);
        try {
            $client = new StripeTaxClient;
            $result = $client->calculate(['currency' => 'usd', 'line_items' => [['amount' => 10000, 'reference' => 'line-0']]]);
            $client->record('taxcalc_x', 'PM-1');
            $client->reverse('tax_x', 'PM-1-refund-1', 10600);
        } finally {
            ApiRequestor::setHttpClient(null);
        }

        $this->assertSame(['id' => 'taxcalc_x', 'tax' => 660, 'shipping_tax' => 60, 'lines' => ['line-0' => 600]], $result);

        $urls = array_map(fn ($r) => parse_url($r['absUrl'], PHP_URL_PATH), $http->requests);
        $this->assertSame([
            '/v1/tax/calculations',
            '/v1/tax/calculations/taxcalc_x/line_items',
            '/v1/tax/transactions/create_from_calculation',
            '/v1/tax/transactions/create_reversal',
        ], $urls);

        $this->assertSame(['calculation' => 'taxcalc_x', 'reference' => 'PM-1'], $http->requests[2]['params']);
        $this->assertSame(['mode' => 'partial', 'original_transaction' => 'tax_x', 'reference' => 'PM-1-refund-1', 'flat_amount' => -10600], $http->requests[3]['params']);
    }

    public function test_admin_launch_check_needs_both_the_switch_and_a_stripe_key(): void
    {
        $service = app(SalesTaxService::class);

        config()->set('marketplace.sales_tax.collecting', true);
        config()->set('services.stripe.secret', null);
        $this->assertFalse($service->ready());

        config()->set('services.stripe.secret', 'sk_test_x');
        $this->assertTrue($service->ready());

        config()->set('marketplace.sales_tax.collecting', false);
        $this->assertFalse($service->ready());
    }
}
