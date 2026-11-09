<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use Database\Seeders\AdminPermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Product photos on seller and admin order screens, and the dashboard without "New listing". */
class OrderProductImagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(AdminPermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake('public');
    }

    private function seller(): User
    {
        $u = User::factory()->create();
        $u->assignRole('seller');
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'Shop '.uniqid(), 'business_type' => 'llc', 'status' => 'approved', 'slug' => 's-'.uniqid()]);

        return $this->withTwoFactor($u);
    }

    private function product(User $seller, bool $photo): Product
    {
        $product = Product::create([
            'seller_id' => $seller->id,
            'category_id' => Category::create(['name' => 'C', 'slug' => 'c-'.Str::random(6)])->id,
            'title' => 'Part '.Str::random(5), 'slug' => 'p-'.Str::random(8),
            'price' => 100, 'quantity' => 10, 'status' => 'active',
        ]);

        if ($photo) {
            $product->addMedia(UploadedFile::fake()->image('caliper.jpg', 800, 800))->toMediaCollection('images');
        }

        return $product->fresh();
    }

    private function line(User $seller, Product $product, ?Order $order = null): OrderItem
    {
        $order ??= Order::create([
            'order_number' => 'PM-'.strtoupper(Str::random(10)), 'buyer_id' => User::factory()->create()->id,
            'status' => OrderStatus::Paid, 'subtotal' => 100, 'shipping_total' => 0, 'discount_total' => 0, 'credits_used' => 0, 'total' => 100,
            'stripe_payment_intent_id' => 'pi_'.Str::random(8),
            'shipping_address' => ['line1' => '1 St'], 'billing_address' => ['line1' => '1 St'], 'placed_at' => now(),
        ]);

        return $order->items()->create([
            'seller_id' => $seller->id, 'product_id' => $product->id, 'product_title' => $product->title,
            'quantity' => 1, 'unit_price' => 100, 'line_total' => 100, 'commission_rate' => 10,
            'platform_fee_amount' => 10, 'seller_payout_amount' => 90, 'status' => OrderStatus::Paid,
        ]);
    }

    public function test_seller_order_list_and_page_show_the_product_photo(): void
    {
        $seller = $this->seller();
        $withPhoto = $this->product($seller, true);
        $item = $this->line($seller, $withPhoto);
        $other = $this->line($seller, $this->product($seller, false), $item->order);
        $photo = $item->imageUrl();

        $this->assertNotNull($photo);
        $this->assertNull($other->imageUrl());

        $this->actingAs($seller)->get(route('seller.orders.index'))->assertOk()
            ->assertSee($photo, false)
            ->assertSee('alt="'.e($withPhoto->title).'"', false);

        // The order page shows it, and the other line on the same order falls back to the icon.
        $this->actingAs($seller)->get(route('seller.orders.show', $item))->assertOk()->assertSee($photo, false);
        $this->actingAs($seller)->get(route('seller.orders.show', $other))->assertOk()
            ->assertSee('Also from you on this order')->assertSee($photo, false);
    }

    public function test_admin_order_list_and_page_show_the_product_photos(): void
    {
        $seller = $this->seller();
        $item = $this->line($seller, $this->product($seller, true));
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $admin = $this->withTwoFactor($admin);

        $this->actingAs($admin)->get(route('admin.orders.index'))->assertOk()->assertSee($item->imageUrl(), false);
        $this->actingAs($admin)->get(route('admin.orders.show', $item->order))->assertOk()->assertSee($item->imageUrl(), false);
    }

    public function test_a_deleted_product_still_lets_the_order_pages_open(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller, true);
        $item = $this->line($seller, $product);
        $product->delete();

        $this->assertNull($item->fresh()->imageUrl());
        $this->actingAs($seller)->get(route('seller.orders.index'))->assertOk()->assertSee($item->product_title);
        $this->actingAs($seller)->get(route('seller.orders.show', $item))->assertOk();
    }

    public function test_seller_dashboard_has_no_new_listing_button(): void
    {
        $this->actingAs($this->seller())->get(route('seller.dashboard'))->assertOk()->assertDontSee('New listing');
    }
}
