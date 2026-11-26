<?php

namespace Tests\Feature\Storefront;

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

/** Checkout marks required fields with * and asks for a phone number the carrier can use. */
class CheckoutPhoneTest extends TestCase
{
    use RefreshDatabase;

    private function buyer(): User
    {
        $product = Product::create([
            'seller_id' => User::factory()->create()->id,
            'category_id' => Category::create(['name' => 'C'.uniqid(), 'slug' => 'c-'.Str::random(6)])->id,
            'title' => 'Brake Pads', 'slug' => 'bp-'.Str::random(6), 'price' => 50, 'quantity' => 10, 'status' => 'active',
        ]);
        $buyer = User::factory()->create(['phone' => null]);
        Cart::create(['user_id' => $buyer->id])->items()->create(['product_id' => $product->id, 'quantity' => 1]);

        return $buyer;
    }

    private function address(array $extra = []): array
    {
        return array_merge(['name' => 'Jane Doe', 'line1' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country' => 'US'], $extra);
    }

    public function test_the_form_marks_required_fields_and_has_a_phone_field(): void
    {
        $html = $this->actingAs($this->buyer())->get(route('checkout.index'))->assertOk()->getContent();

        $this->assertStringContainsString('name="phone"', $html);
        $this->assertMatchesRegularExpression('/id="co-phone"[^>]*type="tel"[^>]*required/s', $html);
        $this->assertMatchesRegularExpression('/id="co-line2"(?![^>]*required)[^>]*>/s', $html); // apartment stays optional
        // Shipping: name, address, city, state, ZIP, phone, country. Billing (when not the same): name, address, city, state, ZIP, country.
        $this->assertSame(13, substr_count($html, '<span class="text-rose-500" aria-hidden="true">*</span>'));
    }

    public function test_phone_is_required_and_must_look_like_a_phone_number(): void
    {
        $buyer = $this->buyer();

        $this->actingAs($buyer)->post(route('checkout.store'), $this->address())
            ->assertSessionHasErrors(['phone' => 'Enter a phone number so the carrier can reach you about delivery.']);
        $this->actingAs($buyer)->post(route('checkout.store'), $this->address(['phone' => '12345']))->assertSessionHasErrors('phone');
        $this->actingAs($buyer)->post(route('checkout.store'), $this->address(['phone' => 'call me maybe 5551234567']))->assertSessionHasErrors('phone');
        $this->actingAs($buyer)->post(route('checkout.store'), ['phone' => '(555) 123-4567'])
            ->assertSessionHasErrors(['name', 'line1', 'city', 'state', 'postal_code', 'country'])->assertSessionDoesntHaveErrors('phone');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_the_phone_is_saved_on_the_order_and_the_account(): void
    {
        $buyer = $this->buyer();
        $this->mock(CheckoutPaymentService::class)->shouldReceive('createPaymentIntent')->once()
            ->andReturn(PaymentIntent::constructFrom(['id' => 'pi_1', 'client_secret' => 's']));

        $this->actingAs($buyer)->post(route('checkout.store'), $this->address(['phone' => '(555) 123-4567']))->assertOk();

        $this->assertSame('+15551234567', Order::firstOrFail()->shipping_address['phone']);
        $this->assertSame('+15551234567', $buyer->fresh()->phone);

        // next time it is filled in already
        $this->actingAs($buyer->fresh())->get(route('cart.index'));
        $this->assertSame('+15551234567', \App\Http\Controllers\Storefront\CheckoutController::cleanPhone('+1 (555) 123-4567'));
    }
}
