<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Livewire\Seller\Inbox;
use App\Livewire\Seller\ProductForm;
use App\Livewire\Seller\Promotions;
use App\Livewire\Storefront\AddToCartButton;
use App\Livewire\Storefront\MessageSeller;
use App\Models\Category;
use App\Models\Conversation;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Marketing\CouponService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SellerAdvancedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seller(string $name = 'Acme Parts'): User
    {
        $user = User::factory()->create();
        $user->assignRole('seller');
        SellerProfile::create(['user_id' => $user->id, 'business_name' => $name, 'business_type' => 'llc', 'status' => 'approved']);

        return $this->withTwoFactor($user);
    }

    private function product(User $seller, array $o = []): Product
    {
        return Product::create(array_merge([
            'seller_id' => $seller->id,
            'category_id' => Category::create(['name' => 'C'.uniqid(), 'slug' => 'c-'.uniqid()])->id,
            'title' => 'Brake Kit',
            'slug' => 'brake-kit-'.uniqid(),
            'price' => 100, 'quantity' => 10, 'status' => 'active',
        ], $o));
    }

    /* ---------- Messaging ---------- */

    public function test_buyer_messages_seller_and_seller_replies(): void
    {
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $product = $this->product($seller);

        Livewire::actingAs($buyer)->test(MessageSeller::class, ['product' => $product])
            ->set('body', 'Does this fit a 2019 Civic?')
            ->call('send')
            ->assertHasNoErrors();

        $conversation = Conversation::first();
        $this->assertNotNull($conversation);
        $this->assertSame($buyer->id, $conversation->buyer_id);
        $this->assertSame($seller->id, $conversation->seller_id);
        $this->assertSame(1, $conversation->seller_unread);
        $this->assertSame(1, $seller->fresh()->unreadSellerMessageCount());
        $this->assertDatabaseCount('notifications', 1);

        Livewire::actingAs($seller)->test(Inbox::class)
            ->call('open', $conversation->id)
            ->set('body', 'Yes, it fits 2016–2021 Civic.')
            ->call('reply')
            ->assertHasNoErrors();

        $conversation->refresh();
        $this->assertSame(0, $conversation->seller_unread);
        $this->assertSame(1, $conversation->buyer_unread);
        $this->assertSame(2, $conversation->messages()->count());
    }

    public function test_cannot_message_own_product(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller);

        Livewire::actingAs($seller)->test(MessageSeller::class, ['product' => $product])
            ->set('body', 'hello there')
            ->call('send')
            ->assertStatus(403);
    }

    /* ---------- Promotions ---------- */

    public function test_seller_creates_promotion_scoped_to_them(): void
    {
        $seller = $this->seller();

        Livewire::actingAs($seller)->test(Promotions::class)
            ->call('newPromotion')
            ->set('code', 'SPRING10')
            ->set('type', 'percentage')
            ->set('value', 10)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('coupons', ['code' => 'SPRING10', 'seller_id' => $seller->id]);
    }

    public function test_seller_coupon_only_discounts_that_sellers_items(): void
    {
        $sellerA = $this->seller('A Parts');
        $sellerB = $this->seller('B Parts');
        $pA = $this->product($sellerA, ['price' => 100]);
        $pB = $this->product($sellerB, ['price' => 100]);

        $coupon = Coupon::create([
            'code' => 'AONLY', 'type' => 'percentage', 'value' => 50,
            'seller_id' => $sellerA->id, 'is_active' => true,
        ]);

        $lines = collect([
            ['product' => $pA, 'line_total' => 100.0],
            ['product' => $pB, 'line_total' => 100.0],
        ]);

        $result = app(CouponService::class)->validate('AONLY', null, 200.0, $lines);

        $this->assertTrue($result['valid']);
        $this->assertSame(100.0, $result['base']);   // only seller A's $100
        $this->assertSame(50.0, $result['discount']); // 50% of $100
    }

    public function test_seller_coupon_rejected_when_seller_not_in_cart(): void
    {
        $sellerA = $this->seller();
        $sellerB = $this->seller();
        $pB = $this->product($sellerB);

        Coupon::create(['code' => 'AONLY2', 'type' => 'fixed', 'value' => 10, 'seller_id' => $sellerA->id, 'is_active' => true]);

        $result = app(CouponService::class)->validate('AONLY2', null, 100.0, collect([
            ['product' => $pB, 'line_total' => 100.0],
        ]));

        $this->assertFalse($result['valid']);
    }

    /* ---------- Analytics / notifications / reviews pages ---------- */

    public function test_analytics_and_notifications_pages_render(): void
    {
        $seller = $this->seller();
        $this->actingAs($seller)->get(route('seller.analytics.index'))->assertOk()->assertSee('Listing performance');
        $this->actingAs($seller)->get(route('seller.notifications.index'))->assertOk();
        $this->actingAs($seller)->get(route('seller.reviews.index'))->assertOk();
    }

    public function test_seller_responds_to_review(): void
    {
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $product = $this->product($seller);
        $review = Review::create([
            'product_id' => $product->id, 'user_id' => $buyer->id, 'rating' => 2,
            'body' => 'Squeaks a lot.', 'status' => 'published', 'is_verified' => true,
        ]);

        $this->actingAs($seller)->from(route('seller.reviews.index'))
            ->post(route('seller.reviews.respond', $review), ['response' => 'Sorry — email us for a free replacement.'])
            ->assertRedirect();

        $this->assertSame('Sorry — email us for a free replacement.', $review->fresh()->seller_response);
        $this->assertNotNull($review->fresh()->seller_responded_at);

        // Shows on the storefront product page
        $this->get(route('products.show', $product->slug))->assertSee('Seller response');
    }

    public function test_other_seller_cannot_respond_to_review(): void
    {
        $seller = $this->seller();
        $other = $this->seller();
        $product = $this->product($seller);
        $review = Review::create(['product_id' => $product->id, 'user_id' => User::factory()->create()->id, 'rating' => 5, 'body' => 'Great', 'status' => 'published']);

        $this->actingAs($other)->post(route('seller.reviews.respond', $review), ['response' => 'x'])->assertForbidden();
    }

    /* ---------- Public seller store ---------- */

    public function test_public_seller_store_page(): void
    {
        $seller = $this->seller('Torque Master');
        $seller->sellerProfile->update(['bio' => 'Brakes and suspension specialists.', 'vacation_mode' => true, 'vacation_message' => 'Back Monday.']);
        $this->product($seller, ['title' => 'Slotted Rotor', 'status' => 'active']);

        $slug = $seller->sellerProfile->slug;
        $this->get(route('sellers.show', $slug))
            ->assertOk()
            ->assertSee('Torque Master')
            ->assertSee('Slotted Rotor')
            ->assertSee('Back Monday.');

        $seller->sellerProfile->update(['status' => 'pending']);
        $this->get(route('sellers.show', $slug))->assertNotFound();
    }

    /* ---------- Variants ---------- */

    public function test_seller_adds_variants_and_buyer_orders_one(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller, ['price' => 80, 'quantity' => 0]);

        Livewire::actingAs($seller)->test(ProductForm::class, ['product' => $product])
            ->call('addVariant')
            ->set('variants.0.label', 'Front axle')
            ->set('variants.0.price', 90)
            ->set('variants.0.quantity', 4)
            ->call('addVariant')
            ->set('variants.1.label', 'Rear axle')
            ->set('variants.1.quantity', 2)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame(2, $product->variants()->count());
        $this->assertTrue($product->fresh()->hasVariants());

        $front = ProductVariant::where('label', 'Front axle')->first();
        $this->assertSame(90.0, $front->effectivePrice()); // override
        $rear = ProductVariant::where('label', 'Rear axle')->first();
        $this->assertSame(80.0, $rear->effectivePrice());   // inherits product price

        // Submitting for review flips status; re-activate to simulate an approved listing.
        $product->fresh()->update(['status' => 'active']);

        // Buyer adds the front variant to cart
        $buyer = User::factory()->create();
        Livewire::actingAs($buyer)->test(AddToCartButton::class, ['product' => $product->fresh()])
            ->set('variantId', $front->id)
            ->set('qty', 2)
            ->call('add')
            ->assertHasNoErrors();

        $this->actingAs($buyer);
        $lines = app(CartService::class)->lines();
        $this->assertCount(1, $lines);
        $this->assertSame($front->id, $lines[0]['variant']->id);
        $this->assertSame(180.0, $lines[0]['line_total']); // 90 * 2
    }
}
