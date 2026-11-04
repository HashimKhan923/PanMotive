<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Livewire\Seller\ApiAccess;
use App\Models\ApiToken;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Jobs\DeliverWebhook;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DisputesAndApiTest extends TestCase
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
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'D '.uniqid(), 'business_type' => 'llc', 'status' => 'approved']);

        return $this->withTwoFactor($u);
    }

    private function deliveredItem(User $seller, User $buyer): OrderItem
    {
        $order = Order::create([
            'order_number' => 'PM-'.strtoupper(uniqid()), 'buyer_id' => $buyer->id,
            'subtotal' => 100, 'total' => 100, 'shipping_address' => ['line1' => 'x'], 'billing_address' => ['line1' => 'x'],
            'status' => OrderStatus::Delivered,
        ]);

        return $order->items()->create([
            'seller_id' => $seller->id,
            'product_id' => Product::create(['seller_id' => $seller->id, 'category_id' => Category::create(['name' => 'C', 'slug' => 'c-'.uniqid()])->id, 'title' => 'P', 'slug' => 'p-'.uniqid(), 'price' => 100, 'quantity' => 2, 'status' => 'active'])->id,
            'product_title' => 'P', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100,
            'commission_rate' => 10, 'platform_fee_amount' => 10, 'seller_payout_amount' => 90,
            'status' => OrderStatus::Delivered,
        ]);
    }

    /* ---------- Disputes / SLA ---------- */

    public function test_return_gets_sla_clock_and_timeline(): void
    {
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $item = $this->deliveredItem($seller, $buyer);

        $this->actingAs($buyer)->post(route('orders.return', $item), ['reason' => 'Wrong part'])->assertRedirect();

        $rma = ReturnRequest::firstOrFail();
        $this->assertNotNull($rma->respond_by);
        $this->assertTrue($rma->respond_by->isFuture());
        $this->assertSame('requested', $rma->events->first()->event);
    }

    public function test_buyer_can_escalate_and_it_alerts_admin(): void
    {
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $item = $this->deliveredItem($seller, $buyer);
        $rma = ReturnRequest::create(['order_item_id' => $item->id, 'user_id' => $buyer->id, 'reason' => 'Damaged', 'status' => 'requested']);

        $this->actingAs($buyer)->from(route('orders.show', $item->order))
            ->post(route('orders.return.escalate', $rma), ['note' => 'No reply for days'])
            ->assertRedirect();

        $rma->refresh();
        $this->assertNotNull($rma->escalated_at);
        $this->assertSame('buyer', $rma->escalated_by);
        $this->assertTrue($rma->events->pluck('event')->contains('escalated'));
        $this->assertDatabaseHas('admin_alerts', ['type' => 'return_escalated']);
    }

    public function test_maintenance_auto_escalates_overdue_returns(): void
    {
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $item = $this->deliveredItem($seller, $buyer);
        $rma = ReturnRequest::create(['order_item_id' => $item->id, 'user_id' => $buyer->id, 'reason' => 'x', 'status' => 'requested']);
        $rma->forceFill(['respond_by' => now()->subHour()])->save();

        $this->artisan('marketplace:maintain')->assertSuccessful();

        $this->assertSame('system', $rma->fresh()->escalated_by);
    }

    /* ---------- Seller API ---------- */

    public function test_api_token_auth_scopes_and_product_write(): void
    {
        $seller = $this->seller();
        $product = Product::create(['seller_id' => $seller->id, 'category_id' => Category::create(['name' => 'C', 'slug' => 'c-'.uniqid()])->id, 'title' => 'Rotor', 'slug' => 'r-'.uniqid(), 'price' => 100, 'quantity' => 10, 'status' => 'active']);

        $readOnly = ApiToken::issue($seller, 'ro', ['products:read'])['plain'];
        $readWrite = ApiToken::issue($seller, 'rw', ['products:read', 'products:write'])['plain'];

        $this->getJson('/api/v1/products', ['Authorization' => "Bearer {$readOnly}"])
            ->assertOk()->assertJsonFragment(['title' => 'Rotor']);

        // read-only token can't write
        $this->patchJson("/api/v1/products/{$product->id}", ['price' => 88], ['Authorization' => "Bearer {$readOnly}"])
            ->assertStatus(403);

        $this->patchJson("/api/v1/products/{$product->id}", ['price' => 88, 'quantity' => 4], ['Authorization' => "Bearer {$readWrite}"])
            ->assertOk()->assertJsonFragment(['price' => 88.0, 'quantity' => 4]);

        $this->getJson('/api/v1/products')->assertStatus(401); // no token
    }

    public function test_api_cannot_touch_another_sellers_product(): void
    {
        $me = $this->seller();
        $other = $this->seller();
        $their = Product::create(['seller_id' => $other->id, 'category_id' => Category::create(['name' => 'C', 'slug' => 'c-'.uniqid()])->id, 'title' => 'X', 'slug' => 'x-'.uniqid(), 'price' => 10, 'quantity' => 1, 'status' => 'active']);

        $token = ApiToken::issue($me, 't', ['products:read', 'products:write'])['plain'];

        $this->getJson("/api/v1/products/{$their->id}", ['Authorization' => "Bearer {$token}"])->assertStatus(404);
    }

    public function test_revoked_token_is_rejected(): void
    {
        $seller = $this->seller();
        $issued = ApiToken::issue($seller, 't', ['products:read']);
        $issued['model']->update(['revoked_at' => now()]);

        $this->getJson('/api/v1/products', ['Authorization' => "Bearer {$issued['plain']}"])->assertStatus(401);
    }

    public function test_webhook_endpoint_creation_and_signed_delivery(): void
    {
        Queue::fake();
        $seller = $this->seller();
        // Public address for the hook host (no real DNS lookups in tests).
        \App\Services\Integrations\WebhookUrlGuard::$resolver = fn () => ['93.184.215.14'];

        Livewire::actingAs($seller)->test(ApiAccess::class)
            ->set('hookUrl', 'https://example.test/hook')
            ->set('hookEvents', ['order.created'])
            ->call('addEndpoint')
            ->assertHasNoErrors();

        $endpoint = WebhookEndpoint::firstOrFail();
        app(\App\Services\Integrations\WebhookDispatcher::class)->fire('order.created', $seller->id, ['x' => 1]);
        Queue::assertPushed(DeliverWebhook::class);

        // The job signs with HMAC.
        Http::fake(['*' => Http::response('', 200)]);
        (new DeliverWebhook($endpoint->id, ['event' => 'order.created', 'data' => ['x' => 1]]))->handle();
        Http::assertSent(fn ($req) => $req->hasHeader('X-Panmotive-Signature')
            && $req->header('X-Panmotive-Signature')[0] === hash_hmac('sha256', $req->body(), $endpoint->secret));

        $this->assertSame('200', $endpoint->fresh()->last_status);
        \App\Services\Integrations\WebhookUrlGuard::$resolver = null;
    }
}
