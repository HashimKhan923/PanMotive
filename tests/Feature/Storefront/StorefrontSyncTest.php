<?php

namespace Tests\Feature\Storefront;

use App\Livewire\Storefront\AddToCartButton;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Marketing\SaleEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** The storefront shows exactly what sellers set up in their portal — no more, no less. */
class StorefrontSyncTest extends TestCase
{
    use RefreshDatabase;

    private function seller(array $profile = []): User
    {
        $u = User::factory()->create();
        SellerProfile::create(array_merge(['user_id' => $u->id, 'business_name' => 'Rotor Room', 'business_type' => 'llc', 'status' => 'approved', 'slug' => 'rotor-room-'.uniqid()], $profile));

        return $u;
    }

    private function product(User $seller, array $extra = []): Product
    {
        return Product::create(array_merge([
            'seller_id' => $seller->id, 'category_id' => Category::create(['name' => 'Brakes', 'slug' => 'b-'.Str::random(5)])->id,
            'title' => 'Slotted Rotor '.Str::random(3), 'slug' => 'r-'.Str::random(8), 'price' => 100, 'quantity' => 5, 'status' => 'active',
        ], $extra))->fresh();
    }

    public function test_scheduled_or_ended_sales_never_show_as_discounts(): void
    {
        $seller = $this->seller();
        $scheduled = $this->product($seller, ['sale_price' => 70, 'sale_starts_at' => now()->addDays(2)]);
        $ended = $this->product($seller, ['sale_price' => 60, 'deal_ends_at' => now()->subHour()]);
        $live = $this->product($seller, ['sale_price' => 80, 'deal_ends_at' => now()->addHours(20)]);

        $this->assertNull($scheduled->liveSalePrice());
        $this->assertNull($ended->liveSalePrice());
        $this->assertEquals(80.0, $live->liveSalePrice());

        $html = $this->get(route('products.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('−30%', $html);
        $this->assertStringNotContainsString('−40%', $html);

        $this->get(route('products.show', $scheduled->slug))->assertOk()->assertDontSee('$70.00');
        $this->get(route('products.show', $live->slug))->assertOk()->assertSee('$80.00')->assertSee('Limited-time deal');
    }

    public function test_product_page_names_the_store_sale(): void
    {
        $seller = $this->seller();
        $p = $this->product($seller);
        app(SaleEventService::class)->create($seller, 'Brake Week', 20, now(), now()->addDays(2), [$p->id]);

        $this->get(route('products.show', $p->fresh()->slug))->assertOk()->assertSee('Brake Week')->assertSee('$80.00');
        $this->get(route('sellers.show', $seller->sellerProfile))->assertOk()->assertSee('Brake Week · 20% off');
    }

    public function test_quick_add_returns_json_and_respects_paused_sellers(): void
    {
        $open = $this->product($this->seller());
        $away = $this->product($this->seller(['vacation_mode' => true, 'vacation_pause_sales' => true]));

        $this->postJson(route('cart.add'), ['product_id' => $open->id])->assertOk()->assertJson(['count' => 1]);
        $this->postJson(route('cart.add'), ['product_id' => $away->id])->assertStatus(422)->assertJsonFragment(['message' => 'This seller is away and not taking orders right now.']);

        Livewire::test(AddToCartButton::class, ['product' => $away])
            ->assertSee('Seller is away')
            ->call('add')->assertReturned(false);
    }

    public function test_store_page_never_advertises_private_codes(): void
    {
        $seller = $this->seller();
        Coupon::create(['code' => 'SPRING10', 'type' => 'percentage', 'value' => 10, 'seller_id' => $seller->id, 'is_active' => true]);
        Coupon::create(['code' => 'FORJANE01', 'type' => 'fixed', 'value' => 15, 'seller_id' => $seller->id, 'user_id' => User::factory()->create()->id, 'is_active' => true]);
        Coupon::create(['code' => 'USEDUP', 'type' => 'fixed', 'value' => 5, 'seller_id' => $seller->id, 'usage_limit' => 1, 'times_used' => 1, 'is_active' => true]);

        $this->get(route('sellers.show', $seller->sellerProfile))->assertOk()
            ->assertSee('SPRING10')->assertDontSee('FORJANE01')->assertDontSee('USEDUP');
    }

    public function test_product_page_shows_delivery_estimate_returns_and_reply_time(): void
    {
        $seller = $this->seller(['return_window_days' => 90, 'return_shipping' => 'seller']);
        $p = $this->product($seller);

        $this->get(route('products.show', $p->slug))->assertOk()
            ->assertSee('Get it')
            ->assertSee('90-day returns · free')
            ->assertSee('data-sticky-buy', false);
    }
}
