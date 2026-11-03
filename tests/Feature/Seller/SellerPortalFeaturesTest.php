<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Livewire\Seller\ProductForm;
use App\Livewire\Seller\ProductList;
use App\Livewire\Seller\StoreSettings;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Models\SellerProfile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SellerPortalFeaturesTest extends TestCase
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
        $user = User::factory()->create();
        $user->assignRole('seller');
        SellerProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Acme Parts',
            'business_type' => 'llc',
            'status' => 'approved',
        ]);

        return $this->withTwoFactor($user);
    }

    private function product(User $seller, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'seller_id' => $seller->id,
            'category_id' => Category::create(['name' => 'Cat '.uniqid(), 'slug' => 'cat-'.uniqid()])->id,
            'title' => 'Brake Kit',
            'slug' => 'brake-kit-'.uniqid(),
            'price' => 120,
            'quantity' => 8,
            'status' => 'active',
        ], $overrides));
    }

    private function orderItem(User $seller, OrderStatus $status = OrderStatus::Delivered): OrderItem
    {
        $order = Order::create([
            'order_number' => 'PM-'.strtoupper(uniqid()),
            'buyer_id' => User::factory()->create()->id,
            'subtotal' => 120, 'total' => 120,
            'shipping_address' => ['name' => 'Pat Buyer', 'line1' => '9 Oak Ave', 'city' => 'Reno', 'state' => 'NV', 'postal_code' => '89501', 'country' => 'US'],
            'billing_address' => ['line1' => '9 Oak Ave'],
            'status' => OrderStatus::Placed,
        ]);

        return $order->items()->create([
            'seller_id' => $seller->id,
            'product_id' => $this->product($seller)->id,
            'product_title' => 'Brake Kit',
            'quantity' => 1,
            'unit_price' => 120,
            'line_total' => 120,
            'commission_rate' => 10,
            'platform_fee_amount' => 12,
            'seller_payout_amount' => 108,
            'status' => $status,
        ]);
    }

    public function test_dashboard_shows_widgets(): void
    {
        $seller = $this->seller();
        $this->orderItem($seller, OrderStatus::Paid);

        $this->actingAs($seller)->get(route('seller.dashboard'))
            ->assertOk()
            ->assertSee('Needs attention')
            ->assertSee('to accept');
    }

    public function test_new_pages_render(): void
    {
        $seller = $this->seller();

        foreach (['seller.returns.index', 'seller.payouts.index', 'seller.settings.edit', 'seller.products.bulk', 'seller.products.create'] as $route) {
            $this->actingAs($seller)->get(route($route))->assertOk();
        }

        $product = $this->product($seller);
        $this->actingAs($seller)->get(route('seller.products.edit', $product))->assertOk()->assertSee($product->title);
    }

    public function test_order_show_page_shows_ship_to_address(): void
    {
        $seller = $this->seller();
        $item = $this->orderItem($seller, OrderStatus::SellerAccepted);

        $this->actingAs($seller)->get(route('seller.orders.show', $item))
            ->assertOk()
            ->assertSee('Pat Buyer')
            ->assertSee('9 Oak Ave')
            ->assertSee('Add tracking'); // no label provider configured in tests → manual tracking
    }

    public function test_product_list_archive_activate_duplicate(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller);

        Livewire::actingAs($seller)->test(ProductList::class)
            ->call('archive', $product->id);
        $this->assertSame('archived', $product->fresh()->status);

        Livewire::actingAs($seller)->test(ProductList::class)
            ->call('activate', $product->id);
        $this->assertSame('active', $product->fresh()->status);

        Livewire::actingAs($seller)->test(ProductList::class)
            ->call('duplicate', $product->id);
        $this->assertDatabaseHas('products', ['title' => 'Brake Kit (copy)', 'status' => 'draft', 'seller_id' => $seller->id]);
    }

    public function test_seller_cannot_archive_another_sellers_product(): void
    {
        $me = $this->seller();
        $other = $this->seller();
        $their = $this->product($other);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::actingAs($me)->test(ProductList::class)->call('archive', $their->id);

        $this->assertSame('active', $their->fresh()->status);
    }

    public function test_product_list_deletes_a_listing_with_no_orders(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller);

        Livewire::actingAs($seller)->test(ProductList::class)->call('delete', $product->id);

        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_product_list_blocks_deleting_a_listing_with_order_history(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller);
        Order::create([
            'order_number' => 'PM-'.strtoupper(uniqid()), 'buyer_id' => User::factory()->create()->id,
            'subtotal' => 120, 'total' => 120,
            'shipping_address' => ['line1' => '9 Oak Ave'], 'billing_address' => ['line1' => '9 Oak Ave'],
            'status' => OrderStatus::Delivered,
        ])->items()->create([
            'seller_id' => $seller->id, 'product_id' => $product->id, 'product_title' => $product->title,
            'quantity' => 1, 'unit_price' => 120, 'line_total' => 120,
            'commission_rate' => 10, 'platform_fee_amount' => 12, 'seller_payout_amount' => 108,
            'status' => OrderStatus::Delivered,
        ]);

        Livewire::actingAs($seller)->test(ProductList::class)->call('delete', $product->id);

        $this->assertNotSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_bulk_delete_skips_listings_with_order_history(): void
    {
        $seller = $this->seller();
        $clean = $this->product($seller);
        $sold = $this->product($seller);
        Order::create([
            'order_number' => 'PM-'.strtoupper(uniqid()), 'buyer_id' => User::factory()->create()->id,
            'subtotal' => 120, 'total' => 120,
            'shipping_address' => ['line1' => '9 Oak Ave'], 'billing_address' => ['line1' => '9 Oak Ave'],
            'status' => OrderStatus::Delivered,
        ])->items()->create([
            'seller_id' => $seller->id, 'product_id' => $sold->id, 'product_title' => $sold->title,
            'quantity' => 1, 'unit_price' => 120, 'line_total' => 120,
            'commission_rate' => 10, 'platform_fee_amount' => 12, 'seller_payout_amount' => 108,
            'status' => OrderStatus::Delivered,
        ]);

        Livewire::actingAs($seller)->test(ProductList::class)
            ->set('selected', [(string) $clean->id, (string) $sold->id])
            ->call('bulk', 'delete');

        $this->assertSoftDeleted('products', ['id' => $clean->id]);
        $this->assertNotSoftDeleted('products', ['id' => $sold->id]);
    }

    public function test_seller_cannot_delete_another_sellers_product(): void
    {
        $me = $this->seller();
        $other = $this->seller();
        $their = $this->product($other);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::actingAs($me)->test(ProductList::class)->call('delete', $their->id);

        $this->assertNotSoftDeleted('products', ['id' => $their->id]);
    }

    public function test_product_form_deletes_the_listing_being_edited(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller);

        Livewire::actingAs($seller)->test(ProductForm::class, ['product' => $product])
            ->call('delete')
            ->assertRedirect(route('seller.products.index'));

        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_product_form_publishes_live_and_saves_drafts(): void
    {
        $seller = $this->seller();
        $category = Category::create(['name' => 'Filters', 'slug' => 'filters-'.uniqid()]);

        Livewire::actingAs($seller)->test(ProductForm::class)
            ->set('title', 'Cabin Air Filter')
            ->set('category_id', $category->id)
            ->set('price', 19.99)
            ->set('quantity', 40)
            ->call('submit')
            ->assertHasNoErrors();

        // Default: an approved seller's listing goes live immediately.
        $filter = \App\Models\Product::where('title', 'Cabin Air Filter')->firstOrFail();
        $this->assertSame('active', $filter->status);
        $this->assertNotNull($filter->published_at);

        Livewire::actingAs($seller)->test(ProductForm::class)
            ->set('title', 'Oil Filter Draft')
            ->set('category_id', $category->id)
            ->set('price', 9.5)
            ->set('quantity', 10)
            ->call('saveDraft')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('products', ['title' => 'Oil Filter Draft', 'status' => 'draft']);
    }

    public function test_product_form_holds_for_review_when_the_platform_requires_it(): void
    {
        config(['marketplace.listings.review_required' => true]);
        $seller = $this->seller();
        $category = Category::create(['name' => 'Filters', 'slug' => 'filters-'.uniqid()]);

        Livewire::actingAs($seller)->test(ProductForm::class)
            ->set('title', 'Reviewed Filter')
            ->set('category_id', $category->id)
            ->set('price', 12)
            ->set('quantity', 5)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('products', ['title' => 'Reviewed Filter', 'status' => 'pending_review']);
    }

    public function test_product_form_saves_rich_description_but_sanitizes_it(): void
    {
        $seller = $this->seller();
        $category = Category::create(['name' => 'Belts', 'slug' => 'belts-'.uniqid()]);

        Livewire::actingAs($seller)->test(ProductForm::class)
            ->set('title', 'Rich Description Part')
            ->set('category_id', $category->id)
            ->set('price', 25)
            ->set('quantity', 3)
            ->set('description', '<p>Direct <strong>OEM</strong> fit.</p><script>alert(1)</script><p onclick="x()">Ships fast.</p>')
            ->call('submit')
            ->assertHasNoErrors();

        $product = \App\Models\Product::where('title', 'Rich Description Part')->firstOrFail();
        $this->assertSame('<p>Direct <strong>OEM</strong> fit.</p><p>Ships fast.</p>', $product->description);
    }

    public function test_product_form_rejects_sale_price_above_price(): void
    {
        $seller = $this->seller();
        $category = Category::create(['name' => 'Belts', 'slug' => 'belts-'.uniqid()]);

        Livewire::actingAs($seller)->test(ProductForm::class)
            ->set('title', 'Serpentine Belt')
            ->set('category_id', $category->id)
            ->set('price', 30)
            ->set('sale_price', 45)
            ->set('quantity', 5)
            ->call('submit')
            ->assertHasErrors('sale_price');
    }

    public function test_seller_can_approve_and_reject_returns(): void
    {
        $seller = $this->seller();

        $approveItem = $this->orderItem($seller, OrderStatus::ReturnRequested);
        $approveRma = ReturnRequest::create(['order_item_id' => $approveItem->id, 'user_id' => $approveItem->order->buyer_id, 'reason' => 'Wrong part', 'status' => 'requested']);

        $this->actingAs($seller)->from(route('seller.returns.index'))
            ->post(route('seller.returns.update', $approveRma), ['decision' => 'approve', 'note' => 'Send it back'])
            ->assertRedirect();

        $this->assertSame('approved', $approveRma->fresh()->status);
        $this->assertSame(OrderStatus::ReturnApproved, $approveItem->fresh()->status);

        $rejectItem = $this->orderItem($seller, OrderStatus::ReturnRequested);
        $rejectRma = ReturnRequest::create(['order_item_id' => $rejectItem->id, 'user_id' => $rejectItem->order->buyer_id, 'reason' => 'Changed mind', 'status' => 'requested']);

        $this->actingAs($seller)->from(route('seller.returns.index'))
            ->post(route('seller.returns.update', $rejectRma), ['decision' => 'reject', 'note' => 'Outside our return policy'])
            ->assertRedirect();

        $this->assertSame('rejected', $rejectRma->fresh()->status);
        $this->assertSame(OrderStatus::Delivered, $rejectItem->fresh()->status);
    }

    public function test_seller_cannot_touch_another_sellers_return(): void
    {
        $me = $this->seller();
        $other = $this->seller();
        $item = $this->orderItem($other, OrderStatus::ReturnRequested);
        $rma = ReturnRequest::create(['order_item_id' => $item->id, 'reason' => 'x', 'status' => 'requested']);

        $this->actingAs($me)->post(route('seller.returns.update', $rma), ['decision' => 'approve'])
            ->assertForbidden();
    }

    public function test_store_settings_persist_to_profile(): void
    {
        $seller = $this->seller();

        Livewire::actingAs($seller)->test(StoreSettings::class)
            ->set('business_name', 'Acme Performance')
            ->set('bio', 'We sell brakes.')
            ->set('return_policy', '30-day returns.')
            ->set('default_carrier', 'ups')
            ->set('processing_days', '3-5')
            ->set('vacation_mode', true)
            ->call('save')
            ->assertHasNoErrors();

        $profile = $seller->sellerProfile->fresh();
        $this->assertSame('Acme Performance', $profile->business_name);
        $this->assertSame('ups', $profile->shipping_settings['default_carrier']);
        $this->assertTrue($profile->vacation_mode);
        $this->assertSame('We sell brakes.', $profile->bio);
        $this->assertSame('30-day returns.', $profile->return_policy);
    }
}
