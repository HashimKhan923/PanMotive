<?php

namespace Tests\Feature\Storefront;

use App\Enums\OrderStatus;
use App\Livewire\Storefront\ProductQuestions;
use App\Livewire\Storefront\ProductReviews;
use App\Livewire\Storefront\VehicleSelector;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Question;
use App\Models\Review;
use App\Models\ReturnRequest;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BuyerExperienceTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $overrides = []): Product
    {
        $seller = User::factory()->create();
        $category = Category::create(['name' => 'Brakes '.uniqid(), 'slug' => 'brakes-'.uniqid()]);

        return Product::create(array_merge([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'title' => 'Test Part',
            'slug' => 'test-part-'.uniqid(),
            'price' => 100,
            'quantity' => 10,
            'status' => 'active',
        ], $overrides));
    }

    private function deliveredItem(User $buyer, Product $product): Order
    {
        $order = Order::create([
            'order_number' => 'PM-'.strtoupper(uniqid()),
            'buyer_id' => $buyer->id,
            'status' => OrderStatus::Delivered,
            'subtotal' => 100, 'total' => 100, 'currency' => 'USD',
            'shipping_address' => ['line1' => '1 A St'],
            'billing_address' => ['line1' => '1 A St'],
        ]);

        $order->items()->create([
            'seller_id' => $product->seller_id,
            'product_id' => $product->id,
            'product_title' => $product->title,
            'product_sku' => $product->sku ?? 'SKU1',
            'quantity' => 1,
            'unit_price' => 100,
            'line_total' => 100,
            'commission_rate' => 10,
            'platform_fee_amount' => 10,
            'seller_payout_amount' => 90,
            'status' => OrderStatus::Delivered,
        ]);

        return $order;
    }

    /* ---------------- Wishlist ---------------- */

    public function test_guest_can_toggle_wishlist_and_read_ids(): void
    {
        $product = $this->product();

        $this->postJson(route('wishlist.toggle'), ['product_id' => $product->id])
            ->assertOk()
            ->assertJson(['in_list' => true, 'count' => 1]);

        $this->getJson(route('wishlist.ids'))
            ->assertOk()
            ->assertJson(['ids' => [$product->id], 'count' => 1]);

        $this->postJson(route('wishlist.toggle'), ['product_id' => $product->id])
            ->assertJson(['in_list' => false, 'count' => 0]);
    }

    public function test_guest_wishlist_merges_into_account_on_login(): void
    {
        $product = $this->product();
        $user = User::factory()->create();

        $this->post(route('wishlist.toggle'), ['product_id' => $product->id])->assertOk();

        $this->actingAs($user)
            ->getJson(route('wishlist.ids'))
            ->assertJson(['ids' => [$product->id]]);
    }

    /* ---------------- Compare ---------------- */

    public function test_compare_caps_at_four_items(): void
    {
        $products = collect(range(1, 5))->map(fn () => $this->product());

        foreach ($products->take(4) as $p) {
            $this->postJson(route('compare.toggle'), ['product_id' => $p->id])
                ->assertOk()
                ->assertJson(['in_list' => true]);
        }

        $this->postJson(route('compare.toggle'), ['product_id' => $products->last()->id])
            ->assertOk()
            ->assertJson(['in_list' => false, 'rejected' => true, 'full' => true]);

        $this->get(route('compare.index'))->assertOk()->assertSee('Compare parts');
    }

    /* ---------------- Deals ---------------- */

    public function test_deals_page_lists_discounted_products(): void
    {
        $this->product(['title' => 'Full Price Widget', 'sale_price' => null]);
        $this->product(['title' => 'Discounted Rotor', 'price' => 100, 'sale_price' => 70]);

        $this->get(route('deals.index'))
            ->assertOk()
            ->assertSee('Discounted Rotor')
            ->assertDontSee('Full Price Widget');
    }

    /* ---------------- Track by email ---------------- */

    public function test_guest_can_track_order_with_matching_email(): void
    {
        $order = Order::create([
            'order_number' => 'PM-TRACK1',
            'buyer_id' => null,
            'guest_email' => 'buyer@example.com',
            'guest_name' => 'Guest Buyer',
            'status' => OrderStatus::Paid,
            'subtotal' => 20, 'total' => 20, 'currency' => 'USD',
            'shipping_address' => ['line1' => '1 A St'],
            'billing_address' => ['line1' => '1 A St'],
        ]);

        $this->post(route('track.lookup'), [
            'order_number' => 'pm-track1',
            'email' => 'BUYER@example.com',
        ])->assertOk()->assertSee('PM-TRACK1');

        $this->from(route('track.form'))
            ->post(route('track.lookup'), ['order_number' => 'PM-TRACK1', 'email' => 'wrong@example.com'])
            ->assertRedirect(route('track.form'))
            ->assertSessionHasErrors('order_number');
    }

    /* ---------------- Reviews ---------------- */

    public function test_only_verified_buyer_can_submit_review(): void
    {
        $product = $this->product();
        $stranger = User::factory()->create();

        Livewire::actingAs($stranger)
            ->test(ProductReviews::class, ['product' => $product])
            ->assertDontSee('Write a review');

        $buyer = User::factory()->create();
        $this->deliveredItem($buyer, $product);

        Livewire::actingAs($buyer)
            ->test(ProductReviews::class, ['product' => $product])
            ->set('showForm', true)
            ->set('rating', 5)
            ->set('body', 'Fits perfectly and shipped fast.')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('reviews', [
            'product_id' => $product->id,
            'user_id' => $buyer->id,
            'is_verified' => true,
            'rating' => 5,
        ]);

        $this->assertEquals(5.0, (float) $product->fresh()->avg_rating);
        $this->assertEquals(1, $product->fresh()->reviews_count);
    }

    /* ---------------- Q&A ---------------- */

    public function test_anyone_can_ask_a_question(): void
    {
        $product = $this->product();

        Livewire::test(ProductQuestions::class, ['product' => $product])
            ->set('questionBody', 'Does this fit a 2019 Civic?')
            ->set('guestName', 'Curious')
            ->call('ask')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('questions', [
            'product_id' => $product->id,
            'author_name' => 'Curious',
            'status' => 'published',
        ]);
    }

    /* ---------------- Garage ---------------- */

    public function test_vehicle_selector_saves_a_garage_vehicle(): void
    {
        $make = VehicleMake::create(['name' => 'Honda', 'slug' => 'honda']);
        $model = VehicleModel::create(['vehicle_make_id' => $make->id, 'name' => 'Civic', 'slug' => 'civic']);
        $vehicle = Vehicle::create([
            'vehicle_make_id' => $make->id,
            'vehicle_model_id' => $model->id,
            'year' => 2019,
        ]);

        Livewire::test(VehicleSelector::class)
            ->set('makeId', $make->id)
            ->set('modelId', $model->id)
            ->set('year', 2019)
            ->call('findParts')
            ->assertRedirect(route('products.index', ['vehicleId' => $vehicle->id]));

        $this->assertEquals($vehicle->id, session('garage.vehicle')['id'] ?? null);
    }

    /* ---------------- Returns / RMA ---------------- */

    public function test_delivered_item_can_start_a_return_with_rma(): void
    {
        $buyer = User::factory()->create();
        $product = $this->product();
        $order = $this->deliveredItem($buyer, $product);
        $item = $order->items()->first();

        $this->actingAs($buyer)
            ->from(route('orders.show', $order))
            ->post(route('orders.return', $item), ['reason' => 'Wrong part received'])
            ->assertRedirect(route('orders.show', $order));

        $rma = ReturnRequest::where('order_item_id', $item->id)->first();
        $this->assertNotNull($rma);
        $this->assertStringStartsWith('RMA-', $rma->rma_number);
        $this->assertEquals(OrderStatus::ReturnRequested, $item->fresh()->status);
    }

    public function test_non_delivered_item_cannot_start_a_return(): void
    {
        $buyer = User::factory()->create();
        $product = $this->product();
        $order = $this->deliveredItem($buyer, $product);
        $item = $order->items()->first();
        $item->update(['status' => OrderStatus::Shipped]);

        $this->actingAs($buyer)
            ->post(route('orders.return', $item), ['reason' => 'Changed my mind'])
            ->assertStatus(422);

        $this->assertDatabaseCount('return_requests', 0);
    }
}
