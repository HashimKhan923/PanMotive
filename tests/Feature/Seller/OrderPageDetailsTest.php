<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Support\OrderInsight;
use Database\Seeders\AdminPermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Order page extras: private notes, risk check, payment, copy and maps. */
class OrderPageDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(AdminPermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seller(): User
    {
        $u = User::factory()->create();
        $u->assignRole('seller');
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'Shop '.uniqid(), 'business_type' => 'llc', 'status' => 'approved', 'slug' => 's-'.uniqid()]);

        return $this->withTwoFactor($u);
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('super_admin');

        return $this->withTwoFactor($u);
    }

    private function line(User $seller, array $order = [], ?User $buyer = null): OrderItem
    {
        $product = Product::create([
            'seller_id' => $seller->id, 'category_id' => Category::create(['name' => 'C', 'slug' => 'c-'.Str::random(6)])->id,
            'title' => 'Part '.Str::random(5), 'slug' => 'p-'.Str::random(8), 'price' => 100, 'quantity' => 10, 'status' => 'active',
        ]);
        $address = ['name' => 'Kevin Haynie', 'line1' => '23409 SE 28th Ct', 'city' => 'Sammamish', 'state' => 'WA', 'postal_code' => '98075', 'country' => 'US', 'phone' => '(425) 623-4651'];
        $o = Order::create(array_merge([
            'order_number' => 'PM-'.strtoupper(Str::random(10)), 'buyer_id' => ($buyer ?? User::factory()->create(['created_at' => now()->subYear()]))->id,
            'status' => OrderStatus::Paid, 'subtotal' => 100, 'shipping_total' => 0, 'discount_total' => 0, 'credits_used' => 0, 'total' => 100,
            'stripe_payment_intent_id' => 'pi_'.Str::random(8), 'shipping_address' => $address, 'billing_address' => $address, 'placed_at' => now(),
        ], $order));

        return $o->items()->create([
            'seller_id' => $seller->id, 'product_id' => $product->id, 'product_title' => $product->title, 'quantity' => 1,
            'unit_price' => 100, 'line_total' => 100, 'commission_rate' => 10, 'platform_fee_amount' => 10, 'seller_payout_amount' => 90,
            'status' => OrderStatus::Shipped, 'carrier' => 'FedEx', 'tracking_number' => '879421308563', 'shipped_at' => now(),
        ]);
    }

    public function test_store_team_adds_private_notes_that_show_by_day_and_staff_notes_stay_hidden(): void
    {
        $seller = $this->seller();
        $item = $this->line($seller);
        $item->statusHistory()->create(['status' => 'staff_note', 'note' => 'Internal: buyer called support twice']);

        $this->actingAs($seller)->post(route('seller.orders.note', $item), ['note' => 'Packed with extra foam'])->assertRedirect();

        $this->assertDatabaseHas('order_status_history', ['order_item_id' => $item->id, 'status' => 'seller_note', 'note' => 'Packed with extra foam', 'changed_by' => $seller->id]);

        $this->actingAs($seller)->get(route('seller.orders.show', $item))->assertOk()
            ->assertSee('Packed with extra foam')
            ->assertSee('Note from '.$seller->name)
            ->assertSee(now()->format('l, M j, Y'))
            ->assertDontSee('Internal: buyer called support twice');

        // Admins see both, labelled.
        $this->actingAs($this->admin())->get(route('admin.orders.show', $item->order))->assertOk()
            ->assertSee('Packed with extra foam')->assertSee('Store note from '.$seller->name)
            ->assertSee('Internal: buyer called support twice')->assertSee("Staff note");
    }

    public function test_notes_are_refused_for_other_stores_and_empty_text(): void
    {
        $item = $this->line($this->seller());

        $this->actingAs($this->seller())->post(route('seller.orders.note', $item), ['note' => 'Not my order'])->assertForbidden();
        $owner = User::find($item->seller_id);
        $this->actingAs($this->withTwoFactor($owner))->post(route('seller.orders.note', $item), ['note' => ''])->assertSessionHasErrors('note');
        $this->actingAs($this->withTwoFactor($owner))->post(route('seller.orders.note', $item), ['note' => str_repeat('x', 1001)])->assertSessionHasErrors('note');

        $this->assertDatabaseMissing('order_status_history', ['status' => 'seller_note']);
    }

    public function test_seller_page_shows_payment_risk_copy_and_maps(): void
    {
        $seller = $this->seller();
        $item = $this->line($seller, ['credits_used' => 20, 'total' => 80]);

        $this->actingAs($seller)->get(route('seller.orders.show', $item))->assertOk()
            ->assertSee('Card + store credit')->assertSee('Paid')
            ->assertSee('Order risk check')->assertSee('Nothing unusual about this order')
            ->assertSee('Copy tracking number')->assertSee('Copy address')
            ->assertSee('https://www.google.com/maps/search/?api=1&amp;query=23409%20SE%2028th%20Ct%2C%20Sammamish%2C%20WA%2C%2098075%2C%20US', false)
            ->assertSee('tel:4256234651', false)
            ->assertSee('Placed '.$item->order->placed_at->format('D, M j, Y').' at ', false);
    }

    public function test_risk_levels_follow_the_fraud_checks(): void
    {
        $seller = $this->seller();

        $low = $this->line($seller);
        $this->assertSame('low', OrderInsight::risk($low->order)['level']);

        $other = ['name' => 'X', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country' => 'US'];
        $medium = $this->line($seller, ['billing_address' => $other]);
        $risk = OrderInsight::risk($medium->order);
        $this->assertSame('medium', $risk['level']);
        $this->assertSame(['Billing and shipping addresses are in different states'], $risk['reasons']);

        $high = $this->line($seller, ['billing_address' => $other, 'buyer_id' => null, 'guest_email' => 'g@example.com', 'guest_name' => 'G', 'total' => 900]);
        $this->assertSame('high', OrderInsight::risk($high->order)['level']);

        $this->actingAs($this->admin())->get(route('admin.orders.show', $high->order))->assertOk()
            ->assertSee('Guest checkout over $500')->assertSee('Billing and shipping addresses are in different states')
            ->assertSee('This order looks unusual')->assertSee('Copy email');
    }

    public function test_payment_status_and_method_wording(): void
    {
        $seller = $this->seller();
        $item = $this->line($seller, ['credits_used' => 100, 'total' => 0]);
        $this->assertSame('Store credit', OrderInsight::paymentMethod($item->order));

        foreach ([[OrderStatus::Placed, 'Awaiting payment'], [OrderStatus::Refunded, 'Refunded'], [OrderStatus::Cancelled, 'Cancelled'], [OrderStatus::Delivered, 'Paid']] as [$status, $label]) {
            $item->status = $status;
            $this->assertSame($label, OrderInsight::paymentStatus($item));
        }
    }
}
