<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\ApiToken;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Notifications\OrderShipped;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OrderShippedNotificationTest extends TestCase
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

    /** @return array{0:Order,1:OrderItem,2:OrderItem} */
    private function acceptedOrder(User $seller, ?User $buyer, ?string $guestEmail = null): array
    {
        $order = Order::create([
            'order_number' => 'PM-'.strtoupper(uniqid()), 'buyer_id' => $buyer?->id,
            'guest_email' => $guestEmail, 'guest_name' => $guestEmail ? 'Guest Gary' : null,
            'subtotal' => 100, 'total' => 100, 'status' => OrderStatus::Paid,
            'shipping_address' => ['name' => 'Jo', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country' => 'US'],
            'billing_address' => ['line1' => 'x'],
        ]);
        $product = Product::create([
            'seller_id' => $seller->id, 'category_id' => Category::create(['name' => 'C'.uniqid(), 'slug' => 'c-'.uniqid()])->id,
            'title' => 'Rotor', 'slug' => 'r-'.uniqid(), 'price' => 50, 'quantity' => 5, 'status' => 'active',
        ]);
        $line = fn (string $title) => $order->items()->create([
            'seller_id' => $seller->id, 'product_id' => $product->id, 'product_title' => $title,
            'quantity' => 1, 'unit_price' => 50, 'line_total' => 50, 'commission_rate' => 10,
            'platform_fee_amount' => 5, 'seller_payout_amount' => 45, 'status' => OrderStatus::SellerAccepted,
        ]);

        return [$order, $line('Front rotor'), $line('Rear rotor')];
    }

    public function test_shipping_from_the_order_page_tells_the_buyer(): void
    {
        Notification::fake();
        $seller = $this->seller();
        $buyer = User::factory()->create();
        [$order, $item] = $this->acceptedOrder($seller, $buyer);

        $this->actingAs($seller)->post(route('seller.orders.ship', $item), ['carrier' => 'UPS', 'tracking_number' => '1Z999'])->assertRedirect();

        Notification::assertSentTo($buyer, OrderShipped::class, fn ($n) => $n->order->is($order) && $n->items->count() === 1);
    }

    public function test_one_package_with_several_items_is_one_message(): void
    {
        Notification::fake();
        $seller = $this->seller();
        $buyer = User::factory()->create();
        [, $item] = $this->acceptedOrder($seller, $buyer);

        $this->actingAs($seller)->post(route('seller.orders.ship', $item), ['carrier' => 'UPS', 'tracking_number' => '1Z999', 'ship_all' => 1])->assertRedirect();

        Notification::assertSentToTimes($buyer, OrderShipped::class, 1);
        Notification::assertSentTo($buyer, OrderShipped::class, fn ($n) => $n->items->count() === 2);
    }

    public function test_a_guest_is_emailed(): void
    {
        Notification::fake();
        $seller = $this->seller();
        [, $item] = $this->acceptedOrder($seller, null, 'gary@example.com');

        $this->actingAs($seller)->post(route('seller.orders.ship', $item), ['carrier' => 'USPS', 'tracking_number' => '9400'])->assertRedirect();

        Notification::assertSentOnDemand(OrderShipped::class, fn ($n, $channels, AnonymousNotifiable $to) => $to->routes['mail'] === 'gary@example.com' && $channels === ['mail']);
    }

    public function test_shipping_through_the_seller_api_tells_the_buyer(): void
    {
        Notification::fake();
        $seller = $this->seller();
        $buyer = User::factory()->create();
        [, $item] = $this->acceptedOrder($seller, $buyer);
        $token = ApiToken::issue($seller, 'ship', ['orders:read', 'orders:write'])['plain'];

        $this->postJson("/api/v1/orders/{$item->id}/ship", ['carrier' => 'FedEx', 'tracking_number' => '7777'], ['Authorization' => "Bearer {$token}"])->assertOk();

        Notification::assertSentTo($buyer, OrderShipped::class);
    }

    public function test_buying_a_label_tells_the_buyer_once_with_the_real_tracking_number(): void
    {
        Notification::fake();
        config()->set('services.easypost.key', 'EZTK_test');
        Http::fake([
            'api.easypost.com/v2/shipments' => Http::response(['id' => 'shp_1', 'rates' => [['id' => 'rate_ups', 'carrier' => 'UPS', 'service' => 'Ground', 'rate' => '9.80', 'delivery_days' => 3]]]),
            'api.easypost.com/v2/shipments/shp_1/buy' => Http::response([
                'tracking_code' => '1Z999AA10123456784',
                'postage_label' => ['label_url' => 'https://easypost-files.s3.amazonaws.com/label.png'],
                'selected_rate' => ['carrier' => 'UPS', 'service' => 'Ground', 'rate' => '9.80'],
            ]),
        ]);
        $seller = $this->seller();
        $seller->sellerProfile->update(['ship_from' => ['name' => 'Dock', 'line1' => '1 Shop Rd', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701']]);
        $buyer = User::factory()->create();
        [$order] = $this->acceptedOrder($seller, $buyer);

        $this->actingAs($seller)->get(route('seller.fulfilment.label', $order))->assertOk();
        $this->actingAs($seller)->post(route('seller.fulfilment.buy-label', $order), ['rate_id' => 'rate_ups'])->assertRedirect();

        Notification::assertSentToTimes($buyer, OrderShipped::class, 1);
        Notification::assertSentTo($buyer, OrderShipped::class, fn ($n) => $n->items->first()->tracking_number === '1Z999AA10123456784');
    }

    public function test_the_email_has_the_items_and_tracking(): void
    {
        $seller = $this->seller();
        $buyer = User::factory()->create();
        [$order, $front, $rear] = $this->acceptedOrder($seller, $buyer);
        $front->update(['carrier' => 'UPS', 'tracking_number' => '1Z999']);
        $rear->update(['carrier' => 'UPS', 'tracking_number' => '1Z999']);

        $html = (string) (new OrderShipped($order, collect([$front->fresh(), $rear->fresh()])))->toMail($buyer)->render();

        $this->assertStringContainsString('Front rotor', $html);
        $this->assertStringContainsString('Rear rotor', $html);
        $this->assertStringContainsString('1Z999', $html);
        $this->assertStringContainsString('https://www.ups.com/track?tracknum=1Z999', $html);
    }

    public function test_a_mail_failure_does_not_undo_or_break_shipping(): void
    {
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp', ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1]);

        $seller = $this->seller();
        [, $item] = $this->acceptedOrder($seller, User::factory()->create());

        $this->actingAs($seller)->post(route('seller.orders.ship', $item), ['carrier' => 'UPS', 'tracking_number' => '1Z999'])
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame(OrderStatus::Shipped, $item->fresh()->status);
    }
}
