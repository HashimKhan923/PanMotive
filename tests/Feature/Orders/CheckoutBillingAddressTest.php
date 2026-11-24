<?php

namespace Tests\Feature\Orders;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Payments\CheckoutPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Stripe\PaymentIntent;
use Tests\TestCase;

class CheckoutBillingAddressTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(CheckoutPaymentService::class)->shouldReceive('createPaymentIntent')
            ->andReturn(PaymentIntent::constructFrom(['id' => 'pi_bill', 'client_secret' => 'pi_bill_secret']));

        $this->buyer = User::factory()->create();
        $product = Product::create([
            'seller_id' => User::factory()->create()->id,
            'category_id' => Category::create(['name' => 'C', 'slug' => 'c-'.Str::random(6)])->id,
            'title' => 'Widget', 'slug' => 'widget-'.Str::random(6), 'price' => 100, 'quantity' => 10, 'status' => 'active',
        ]);
        Cart::create(['user_id' => $this->buyer->id])->items()->create(['product_id' => $product->id, 'quantity' => 1]);
    }

    private function shipping(): array
    {
        return ['name' => 'Jane Buyer', 'line1' => '1 Main St', 'city' => 'Detroit', 'state' => 'MI', 'postal_code' => '48201', 'country' => 'US', 'phone' => '+15551234567'];
    }

    public function test_the_checkout_page_has_a_shipping_and_a_billing_address(): void
    {
        $this->actingAs($this->buyer)->get(route('checkout.index'))->assertOk()
            ->assertSee('Shipping address')->assertSee('Billing address')->assertSee('Same as shipping address');
    }

    public function test_same_as_shipping_copies_the_shipping_address(): void
    {
        $this->actingAs($this->buyer)->post(route('checkout.store'), $this->shipping() + ['billing_same' => '1'])->assertOk();

        $order = Order::firstOrFail();
        $this->assertSame('1 Main St', $order->billing_address['line1']);
        $this->assertSame($order->shipping_address, $order->billing_address);
    }

    public function test_a_different_billing_address_is_stored_separately(): void
    {
        $this->actingAs($this->buyer)->post(route('checkout.store'), $this->shipping() + [
            'billing_same' => '0',
            'billing' => ['name' => 'Jane Card', 'line1' => '9 Bank Ave', 'city' => 'Austin', 'state' => 'Texas', 'postal_code' => '73301', 'country' => 'us'],
        ])->assertOk();

        $order = Order::firstOrFail();
        $this->assertSame('1 Main St', $order->shipping_address['line1']);
        $this->assertSame('9 Bank Ave', $order->billing_address['line1']);
        $this->assertSame('TX', $order->billing_address['state']);
        $this->assertSame('US', $order->billing_address['country']);
        $this->assertArrayNotHasKey('billing', $order->shipping_address);
    }

    public function test_a_different_billing_address_must_be_filled_in(): void
    {
        $this->actingAs($this->buyer)->from(route('checkout.index'))
            ->post(route('checkout.store'), $this->shipping() + ['billing_same' => '0', 'billing' => ['name' => 'Jane']])
            ->assertSessionHasErrors(['billing.line1', 'billing.city', 'billing.state', 'billing.postal_code']);
        $this->assertSame(0, Order::count());
    }

    public function test_membership_has_a_plain_name_and_the_old_address_forwards(): void
    {
        $this->get('/plus')->assertStatus(301)->assertRedirect('/membership');
        $this->get('/membership')->assertOk()->assertSee('Panmotive')->assertSee('Membership')->assertDontSee('Join Plus');
    }
}
