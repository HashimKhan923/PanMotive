<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Livewire\Seller\BulkUpdate;
use App\Livewire\Seller\Bundles;
use App\Livewire\Seller\Inventory;
use App\Livewire\Seller\ProductList;
use App\Livewire\Seller\ShippingPolicies;
use App\Models\Bundle;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\InventoryLocation;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\SellerProfile;
use App\Models\ShippingPolicy;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\Orders\OrderCancellationService;
use App\Services\Payments\CheckoutPaymentService;
use App\Services\Payments\RefundService;
use App\Services\Seller\InventoryService;
use App\Services\Shipping\ShippingEstimateService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Stripe\PaymentIntent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CatalogCompletionTest extends TestCase
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

    private function product(User $seller, float $price = 100, int $qty = 10, array $extra = []): Product
    {
        return Product::create(array_merge([
            'seller_id' => $seller->id,
            'category_id' => Category::create(['name' => 'C', 'slug' => 'c-'.Str::random(6)])->id,
            'title' => 'Part '.Str::random(5), 'slug' => 'p-'.Str::random(8),
            'price' => $price, 'quantity' => $qty, 'status' => 'active', 'sku' => 'SKU-'.Str::random(5),
        ], $extra))->fresh();
    }

    private function bundle(User $seller, array $products, float $pct, array $qtys = []): Bundle
    {
        $b = Bundle::create(['seller_id' => $seller->id, 'title' => 'Kit '.Str::random(4), 'discount_type' => 'percentage', 'discount_value' => $pct, 'is_active' => true]);
        foreach ($products as $i => $p) {
            $b->items()->create(['product_id' => $p->id, 'quantity' => $qtys[$i] ?? 1]);
        }

        return $b;
    }

    /** Fill a buyer's cart, check out, return the order. */
    private function checkout(User $buyer, array $lines, array $address = [], ?string $coupon = null)
    {
        $this->mock(CheckoutPaymentService::class)->shouldReceive('createPaymentIntent')
            ->andReturn(PaymentIntent::constructFrom(['id' => 'pi_'.Str::random(8), 'client_secret' => 'x']));
        $cart = Cart::firstOrCreate(['user_id' => $buyer->id]);
        foreach ($lines as $l) {
            $cart->items()->create(['product_id' => $l[0]->id, 'quantity' => $l[1], 'product_variant_id' => $l[2] ?? null]);
        }
        $session = $coupon ? ['checkout.coupon_code' => $coupon] : [];

        return $this->actingAs($buyer)->withSession($session)->post(route('checkout.store'), array_merge([
            'name' => 'B', 'line1' => '1 St', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country' => 'US',
        ], $address));
    }

    // ================= bundles =================

    public function test_overlapping_bundles_do_not_double_discount_the_same_units(): void
    {
        $seller = $this->seller();
        [$pads, $rotors, $kit] = [$this->product($seller, 50), $this->product($seller, 100), $this->product($seller, 20)];
        $this->bundle($seller, [$pads, $rotors], 10);          // saves $15
        $this->bundle($seller, [$pads, $rotors, $kit], 20);    // saves $34 — better

        $buyer = User::factory()->create();
        $this->actingAs($buyer);
        $cart = app(CartService::class);
        foreach ([$pads, $rotors, $kit] as $p) {
            $cart->add($p->id, 1);
        }

        $d = $cart->bundleDiscount();
        $this->assertCount(1, $d['applied']);           // one set of units → one bundle
        $this->assertEquals(34.00, $d['total']);        // the better one
    }

    public function test_two_full_sets_get_two_discounts_and_variant_lines_add_up(): void
    {
        $seller = $this->seller();
        $pads = $this->product($seller, 50);
        $rotors = $this->product($seller, 100);
        $red = $rotors->variants()->create(['label' => 'Red', 'options' => ['Color' => 'Red'], 'quantity' => 5]);
        $black = $rotors->variants()->create(['label' => 'Black', 'options' => ['Color' => 'Black'], 'quantity' => 5]);
        $this->bundle($seller, [$pads, $rotors], 10); // $15 per set

        $this->actingAs(User::factory()->create());
        $cart = app(CartService::class);
        $cart->add($pads->id, 2);
        $cart->add($rotors->id, 1, $red->id);
        $cart->add($rotors->id, 1, $black->id); // two variant lines = 2 rotors

        $this->assertEquals(30.00, $cart->bundleDiscount()['total']);
    }

    public function test_bundles_with_an_unavailable_item_are_not_offered(): void
    {
        $seller = $this->seller();
        $pads = $this->product($seller, 50);
        $rotors = $this->product($seller, 100, qty: 0);
        $bundle = $this->bundle($seller, [$pads, $rotors], 10);

        $this->assertFalse($bundle->fresh('products')->isAvailable());
        $this->get(route('products.show', $pads->slug))->assertOk()->assertDontSee($bundle->title);
        $this->actingAs(User::factory()->create())->post(route('cart.add-bundle', $bundle))->assertNotFound();
    }

    public function test_add_bundle_to_cart_adds_every_item(): void
    {
        $seller = $this->seller();
        $pads = $this->product($seller, 50);
        $rotors = $this->product($seller, 100);
        $bundle = $this->bundle($seller, [$pads, $rotors], 10, [1, 2]);

        $this->get(route('products.show', $pads->slug))->assertSee('Add bundle to cart');

        $buyer = User::factory()->create();
        $this->actingAs($buyer)->post(route('cart.add-bundle', $bundle))->assertRedirect(route('cart.index'));

        $items = Cart::where('user_id', $buyer->id)->first()->items->pluck('quantity', 'product_id');
        $this->assertSame(1, $items[$pads->id]);
        $this->assertSame(2, $items[$rotors->id]);
    }

    public function test_bundle_form_rejects_duplicates_and_oversized_fixed_discounts(): void
    {
        $seller = $this->seller();
        $a = $this->product($seller, 50);
        $b = $this->product($seller, 30);

        Livewire::actingAs($seller)->test(Bundles::class)
            ->call('newBundle')->set('title', 'Kit')->set('discount_value', 10)
            ->set('lines', [['product_id' => $a->id, 'quantity' => 1], ['product_id' => $a->id, 'quantity' => 1]])
            ->call('save')->assertHasErrors('lines.1.product_id');

        Livewire::actingAs($seller)->test(Bundles::class)
            ->call('newBundle')->set('title', 'Kit')->set('discount_type', 'fixed')->set('discount_value', 90)
            ->set('lines', [['product_id' => $a->id, 'quantity' => 1], ['product_id' => $b->id, 'quantity' => 1]])
            ->call('save')->assertHasErrors('discount_value'); // bundle is only worth $80

        $this->assertSame(0, Bundle::count());
    }

    // ================= seller-funded discounts =================

    public function test_bundle_savings_come_off_the_sellers_lines_not_the_platform(): void
    {
        $seller = $this->seller();
        $pads = $this->product($seller, 50);
        $rotors = $this->product($seller, 150);
        $this->bundle($seller, [$pads, $rotors], 10); // saves $20

        $this->checkout(User::factory()->create(), [[$pads, 1], [$rotors, 1]])->assertOk();
        $order = Order::firstOrFail();

        $this->assertEquals(20, (float) $order->discount_total);
        $this->assertEquals(20, (float) $order->items()->sum('discount_amount'));
        $this->assertEquals(180, (float) $order->items()->sum('line_total'));          // net of the bundle saving
        $this->assertEquals(162, (float) $order->items()->sum('seller_payout_amount')); // 10% commission on $180
        $this->assertEquals(5, (float) $order->items()->where('product_id', $pads->id)->value('discount_amount'));  // 50/200 of $20
    }

    public function test_seller_coupons_are_seller_funded_but_platform_coupons_are_not(): void
    {
        $seller = $this->seller();
        $other = $this->seller();
        $mine = $this->product($seller, 100);
        $theirs = $this->product($other, 100);
        Coupon::create(['code' => 'SHOP10', 'type' => 'percentage', 'value' => 10, 'seller_id' => $seller->id, 'is_active' => true]);
        Coupon::create(['code' => 'PM5', 'type' => 'fixed', 'value' => 5, 'is_active' => true]);

        $this->checkout(User::factory()->create(), [[$mine, 1], [$theirs, 1]], coupon: 'SHOP10')->assertOk();
        $order = Order::latest('id')->first();
        $this->assertEquals(10, (float) $order->items()->where('seller_id', $seller->id)->value('discount_amount'));
        $this->assertEquals(0, (float) $order->items()->where('seller_id', $other->id)->value('discount_amount'));
        $this->assertDatabaseHas('coupon_usages', ['order_id' => $order->id, 'discount_amount' => 10]);

        $this->checkout(User::factory()->create(), [[$mine, 1]], coupon: 'PM5')->assertOk();
        $order2 = Order::latest('id')->first();
        $this->assertEquals(5, (float) $order2->discount_total);
        $this->assertEquals(0, (float) $order2->items()->sum('discount_amount')); // our promo, our cost
        $this->assertEquals(90, (float) $order2->items()->value('seller_payout_amount'));
    }

    public function test_cancelling_a_bundle_line_refunds_what_the_buyer_actually_paid(): void
    {
        Notification::fake();
        $refunds = $this->mock(RefundService::class);
        $refunds->shouldReceive('refundAmount')->once()->withArgs(fn ($o, $amount) => abs($amount - 45) < 0.01);

        $seller = $this->seller();
        $pads = $this->product($seller, 50);
        $rotors = $this->product($seller, 150);
        $this->bundle($seller, [$pads, $rotors], 10);
        $this->checkout(User::factory()->create(), [[$pads, 1], [$rotors, 1]])->assertOk();
        $order = Order::firstOrFail();
        $order->update(['status' => OrderStatus::Paid]);
        $order->items()->update(['status' => OrderStatus::Paid]);

        $padsLine = $order->items()->where('product_id', $pads->id)->first();
        app(OrderCancellationService::class)->cancel($padsLine, 'out_of_stock', null, $seller); // paid $50 − $5 = $45
    }

    // ================= inventory & variants =================

    public function test_variant_stock_drives_the_listing_total(): void
    {
        $p = $this->product($this->seller(), qty: 99);
        $v1 = $p->variants()->create(['label' => 'L', 'options' => ['Side' => 'L'], 'quantity' => 3]);
        $p->variants()->create(['label' => 'R', 'options' => ['Side' => 'R'], 'quantity' => 4]);
        $this->assertSame(7, $p->fresh()->quantity);

        $v1->update(['quantity' => 0]);
        $this->assertSame(4, $p->fresh()->quantity);
    }

    public function test_adjusting_a_listing_sold_by_option_requires_the_option(): void
    {
        $p = $this->product($this->seller());
        $v = $p->variants()->create(['label' => 'L', 'options' => ['Side' => 'L'], 'quantity' => 3]);
        $svc = app(InventoryService::class);

        try {
            $svc->adjust($p->fresh(), 5, 'restock');
            $this->fail('Expected a 422 for a listing-level adjust');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $svc->adjust($p->fresh(), 5, 'restock', ['variant_id' => $v->id]);
        $this->assertSame(8, $v->fresh()->quantity);
        $this->assertSame(8, $p->fresh()->quantity);
        $this->assertSame(8, StockMovement::latest('id')->first()->balance_after);
    }

    public function test_inventory_page_adjusts_options_and_sets_exact_counts(): void
    {
        $seller = $this->seller();
        $p = $this->product($seller, qty: 10);
        $withOpts = $this->product($seller);
        $v = $withOpts->variants()->create(['label' => 'Blue', 'options' => ['Color' => 'Blue'], 'quantity' => 2]);

        Livewire::actingAs($seller)->test(Inventory::class)
            ->assertSee('Blue')
            ->call('openAdjust', $withOpts->id, $v->id)->set('adjustDelta', 3)->call('applyAdjust')
            ->call('openAdjust', $p->id)->set('adjustMode', 'set')->set('adjustTarget', 7)->call('applyAdjust');

        $this->assertSame(5, $v->fresh()->quantity);
        $this->assertSame(7, $p->fresh()->quantity);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $p->id, 'delta' => -3, 'reason' => 'correction', 'note' => 'Stock count']);
    }

    public function test_transfer_between_locations_never_loses_stock(): void
    {
        $seller = $this->seller();
        $main = InventoryLocation::create(['seller_id' => $seller->id, 'name' => 'Main', 'is_default' => true]);
        $west = InventoryLocation::create(['seller_id' => $seller->id, 'name' => 'West']);
        $p = $this->product($seller, qty: 10); // never tracked by location before

        Livewire::actingAs($seller)->test(Inventory::class)
            ->call('openTransfer', $p->id)->set('transferFrom', $main->id)->set('transferTo', $west->id)->set('transferQty', 4)
            ->call('applyTransfer')->assertHasNoErrors();

        $this->assertSame(10, $p->fresh()->quantity);
        $this->assertSame(6, ProductStock::where('inventory_location_id', $main->id)->value('quantity'));
        $this->assertSame(4, ProductStock::where('inventory_location_id', $west->id)->value('quantity'));

        Livewire::actingAs($seller)->test(Inventory::class)
            ->call('openTransfer', $p->id)->set('transferFrom', $west->id)->set('transferTo', $main->id)->set('transferQty', 50)
            ->call('applyTransfer')->assertHasErrors('transferQty');

        Livewire::actingAs($seller)->test(Inventory::class)->call('deleteLocation', $west->id);
        $this->assertNotNull($west->fresh()); // still has stock → kept
    }

    public function test_quick_edit_logs_stock_and_leaves_option_stock_alone(): void
    {
        $seller = $this->seller();
        $p = $this->product($seller, qty: 10);

        Livewire::actingAs($seller)->test(ProductList::class)
            ->call('startEdit', $p->id)->set('editPrice', 120)->set('editQty', 4)->call('saveEdit');

        $this->assertSame(4, $p->fresh()->quantity);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $p->id, 'delta' => -6, 'note' => 'Quick edit on the product list']);
    }

    public function test_checkout_takes_option_stock_and_logs_it_as_system(): void
    {
        $seller = $this->seller();
        $p = $this->product($seller, 40);
        $v = $p->variants()->create(['label' => 'Blue', 'options' => ['Color' => 'Blue'], 'quantity' => 5]);

        $this->checkout(User::factory()->create(), [[$p, 2, $v->id]])->assertOk();

        $this->assertSame(3, $v->fresh()->quantity);
        $this->assertSame(3, $p->fresh()->quantity);
        $this->assertSame('System', StockMovement::where('reason', 'sale')->value('actor_name'));
    }

    // ================= spreadsheet bulk update =================

    public function test_spreadsheet_round_trip_previews_then_applies(): void
    {
        $seller = $this->seller();
        $simple = $this->product($seller, 50, 10, ['sku' => 'PAD-1']);
        $other = $this->product($seller, 30, 5, ['sku' => 'PAD-2']);
        $opts = $this->product($seller, 80, 0, ['sku' => 'ROT']);
        $blue = $opts->variants()->create(['label' => 'Blue', 'options' => ['Color' => 'Blue'], 'quantity' => 2, 'sku' => 'ROT-BLU']);
        $foreign = $this->product($this->seller());

        $csv = implode("\n", [
            'listing_id,option_id,sku,title,option,price,sale_price,quantity,status',
            "{$simple->id},,PAD-1,x,,45.00,,12,",   // price + stock
            ",,ROT-BLU,x,Blue,,,9,",               // option matched by SKU
            ",,NOPE,x,,10,,,",                     // unknown SKU
            "{$foreign->id},,,x,,1,,,",            // not theirs
            ",,PAD-2,x,,-5,,,",                    // negative price must be rejected, not read as $5
        ])."\n";

        $c = Livewire::actingAs($seller)->test(BulkUpdate::class)
            ->set('sheet', UploadedFile::fake()->createWithContent('u.csv', $csv))
            ->call('check');

        $result = cache()->get('catalog-bulk-update:'.$seller->id.':'.$c->get('token'));
        $this->assertCount(2, $result['changes']);
        $this->assertCount(3, $result['errors']);
        $this->assertEquals(50, (float) $simple->fresh()->price); // nothing written yet

        $c->call('apply');

        $this->assertEquals(45, (float) $simple->fresh()->price);
        $this->assertSame(12, $simple->fresh()->quantity);
        $this->assertSame(9, $blue->fresh()->quantity);
        $this->assertSame(9, $opts->fresh()->quantity);
        $this->assertEquals(30, (float) $other->fresh()->price); // the -5 row was skipped
        $this->assertDatabaseHas('stock_movements', ['product_id' => $simple->id, 'delta' => 2, 'note' => 'Spreadsheet update']);
    }

    public function test_spreadsheet_export_has_one_row_per_option(): void
    {
        $seller = $this->seller();
        $opts = $this->product($seller);
        $opts->variants()->create(['label' => 'Blue', 'options' => ['Color' => 'Blue'], 'quantity' => 2]);
        $opts->variants()->create(['label' => 'Red', 'options' => ['Color' => 'Red'], 'quantity' => 1]);
        $this->product($seller);

        $rows = app(\App\Services\Seller\CatalogBulkUpdateService::class)->exportRows($seller->id);
        $this->assertCount(3, $rows);
    }

    // ================= shipping =================

    public function test_free_flat_with_extra_items_and_weight_rates(): void
    {
        $seller = $this->seller();
        $svc = app(ShippingEstimateService::class);
        // Kept under the platform's own free-shipping threshold so only the policy decides.
        $p = $this->product($seller, 20, extra: ['weight_lbs' => 3]);
        $lines = [['product' => $p, 'quantity' => 3, 'line_total' => 60]];

        $this->assertEquals(12.99, $svc->estimate($lines)['shipping']); // no policy → by weight (9 lbs = 5–20 lb band)

        ShippingPolicy::create(['seller_id' => $seller->id, 'name' => 'Flat', 'is_default' => true, 'handling_days' => 1, 'flat_rate' => 8, 'additional_item_rate' => 2]);
        $this->assertEquals(12.00, $svc->estimate($lines)['shipping']); // $8 + 2 × $2

        ShippingPolicy::where('seller_id', $seller->id)->update(['flat_rate' => 0, 'additional_item_rate' => null]);
        $this->assertEquals(0.0, $svc->estimate($lines)['shipping']);
    }

    public function test_excluded_states_block_checkout_even_when_spelled_out(): void
    {
        $seller = $this->seller();
        ShippingPolicy::create(['seller_id' => $seller->id, 'name' => 'CARB', 'is_default' => true, 'handling_days' => 2, 'excluded_states' => ['CA']]);
        $exhaust = $this->product($seller, 300);

        $this->checkout(User::factory()->create(), [[$exhaust, 1]], ['state' => 'California'])
            ->assertSessionHasErrors('state');
        $this->assertSame(0, Order::count());

        $this->get(route('products.show', $exhaust->slug))->assertSee("Doesn't ship to: CA", false);

        $this->checkout(User::factory()->create(), [[$exhaust, 1]], ['state' => 'nevada'])->assertOk();
        $this->assertSame('NV', Order::first()->shipping_address['state']);
    }

    public function test_state_names_normalize_to_codes(): void
    {
        $this->assertSame('CA', ShippingEstimateService::normalizeState(' california '));
        $this->assertSame('DC', ShippingEstimateService::normalizeState('Washington D.C.'));
        $this->assertSame('TX', ShippingEstimateService::normalizeState('tx'));
        $this->assertNull(ShippingEstimateService::normalizeState(''));
    }

    public function test_policy_form_presets_default_and_safe_delete(): void
    {
        $seller = $this->seller();

        Livewire::actingAs($seller)->test(ShippingPolicies::class)
            ->set('name', 'Standard')->set('method', 'flat')->set('flatRate', 9)->set('additionalRate', 2)
            ->call('addPreset', 'Alaska & Hawaii')
            ->set('stateToAdd', 'ca')->call('addExcluded')
            ->call('save')->assertHasNoErrors();

        $p = ShippingPolicy::firstOrFail();
        $this->assertTrue($p->is_default); // first policy becomes the default
        $this->assertSame(['AK', 'HI', 'CA'], $p->excluded_states);
        $this->assertSame('$9.00 flat + $2.00 each additional item', $p->rateLabel());

        Livewire::actingAs($seller)->test(ShippingPolicies::class)
            ->set('name', 'Free')->set('method', 'free')->call('save');
        $free = ShippingPolicy::where('name', 'Free')->first();
        $this->assertEquals(0, (float) $free->flat_rate);

        $listing = $this->product($seller, extra: ['shipping_policy_id' => $p->id]);
        Livewire::actingAs($seller)->test(ShippingPolicies::class)->call('delete', $p->id);
        $this->assertNull($listing->fresh()->shipping_policy_id);
        $this->assertTrue($free->fresh()->is_default); // a default is kept
    }

    public function test_every_catalog_page_renders(): void
    {
        $seller = $this->seller();
        $this->product($seller);
        foreach (['seller.products.index', 'seller.inventory.index', 'seller.bundles.index', 'seller.shipping.index', 'seller.products.bulk-update', 'seller.products.bulk'] as $r) {
            $this->actingAs($seller)->get(route($r))->assertOk();
        }
        foreach (['low', 'out', 'locations', 'history'] as $tab) {
            $this->actingAs($seller)->get(route('seller.inventory.index', ['tab' => $tab]))->assertOk();
        }
    }
}
