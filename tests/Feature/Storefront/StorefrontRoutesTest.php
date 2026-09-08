<?php

namespace Tests\Feature\Storefront;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_renders(): void
    {
        $this->get(route('home'))->assertOk();
    }

    public function test_shop_page_renders(): void
    {
        $this->get(route('products.index'))->assertOk()->assertSee('Shop');
    }

    public function test_cart_page_renders(): void
    {
        $this->get(route('cart.index'))->assertOk()->assertSee('Your cart');
    }

    public function test_checkout_index_renders(): void
    {
        $this->get(route('checkout.index'))->assertOk();
    }

    public function test_checkout_store_redirects_to_cart_when_empty(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('checkout.store'))
            ->assertRedirect(route('cart.index'))
            ->assertSessionHas('status');
    }

    public function test_product_show_renders_for_active_product(): void
    {
        $seller = User::factory()->create();
        $category = Category::create(['name' => 'Brake Pads', 'slug' => 'brake-pads-test']);

        $product = Product::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'title' => 'Ceramic Brake Pad Set',
            'slug' => 'ceramic-brake-pad-set',
            'price' => 89.99,
            'quantity' => 5,
            'status' => 'active',
        ]);

        $this->get(route('products.show', $product->slug))
            ->assertOk()
            ->assertSee('Ceramic Brake Pad Set');
    }

    public function test_product_show_404s_for_draft_product(): void
    {
        $seller = User::factory()->create();
        $category = Category::create(['name' => 'Filters', 'slug' => 'filters-test']);

        $product = Product::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'title' => 'Oil Filter',
            'slug' => 'oil-filter',
            'price' => 12.00,
            'quantity' => 5,
            'status' => 'draft',
        ]);

        $this->get(route('products.show', $product->slug))->assertNotFound();
    }

    public function test_checkout_confirmation_renders_for_existing_order(): void
    {
        $buyer = User::factory()->create();

        $order = Order::create([
            'order_number' => 'PM-TEST-1',
            'buyer_id' => $buyer->id,
            'subtotal' => 50,
            'total' => 50,
            'shipping_address' => ['line1' => '1 A St'],
            'billing_address' => ['line1' => '1 A St'],
            'status' => 'placed',
        ]);

        $this->get(route('checkout.confirmation', $order->order_number))
            ->assertOk()
            ->assertSee('PM-TEST-1');
    }
}
