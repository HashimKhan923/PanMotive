<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Jobs\DeliverWebhook;
use App\Livewire\Seller\ApiAccess;
use App\Livewire\Seller\StoreSettings;
use App\Livewire\Seller\Team;
use App\Livewire\Seller\Verification;
use App\Models\ApiToken;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\SellerTeamMember;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Integrations\WebhookUrlGuard;
use App\Services\MessagingService;
use App\Services\Payments\CheckoutPaymentService;
use App\Support\SellerContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Stripe\PaymentIntent;
use Tests\TestCase;

class SettingsSectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        WebhookUrlGuard::$resolver = fn () => ['93.184.215.14'];
    }

    protected function tearDown(): void
    {
        WebhookUrlGuard::$resolver = null;
        parent::tearDown();
    }

    private function seller(array $profile = []): User
    {
        $u = User::factory()->create();
        $u->assignRole('seller');
        SellerProfile::create(array_merge(['user_id' => $u->id, 'business_name' => 'Gear Garage', 'business_type' => 'llc', 'status' => 'approved', 'slug' => 's-'.uniqid()], $profile));

        return $this->withTwoFactor($u);
    }

    private function product(User $seller, array $extra = []): Product
    {
        return Product::create(array_merge([
            'seller_id' => $seller->id, 'category_id' => Category::create(['name' => 'C', 'slug' => 'c-'.Str::random(6)])->id,
            'title' => 'Strut '.Str::random(3), 'slug' => 'p-'.Str::random(8), 'price' => 80, 'quantity' => 5, 'status' => 'active',
        ], $extra))->fresh();
    }

    private function line(User $seller, OrderStatus $status, array $extra = []): OrderItem
    {
        $product = $this->product($seller);
        $order = Order::create([
            'order_number' => 'PM-'.strtoupper(Str::random(10)), 'buyer_id' => User::factory()->create()->id, 'status' => OrderStatus::Paid,
            'subtotal' => 80, 'total' => 80, 'shipping_address' => ['line1' => 'x'], 'billing_address' => ['line1' => 'x'], 'placed_at' => now(),
        ]);

        return $order->items()->create(array_merge([
            'seller_id' => $seller->id, 'product_id' => $product->id, 'product_title' => $product->title, 'quantity' => 1,
            'unit_price' => 80, 'line_total' => 80, 'commission_rate' => 10, 'platform_fee_amount' => 8, 'seller_payout_amount' => 72, 'status' => $status,
        ], $extra));
    }

    private function staff(User $owner, array $abilities): User
    {
        $u = $this->withTwoFactor(User::factory()->create(['email' => Str::random(6).'@example.com']));
        SellerTeamMember::create(['owner_id' => $owner->id, 'user_id' => $u->id, 'email' => $u->email, 'role' => 'custom', 'abilities' => $abilities, 'status' => 'active', 'accepted_at' => now()]);

        return $u;
    }

    // ================= store settings =================

    public function test_store_processing_time_drives_the_ship_by_date(): void
    {
        $seller = $this->seller(['shipping_settings' => ['processing_days' => '5-7']]);
        $monday = Carbon::parse('next monday 09:00');
        $line = $this->line($seller, OrderStatus::Paid);
        $line->order->update(['placed_at' => $monday]);

        // No listing or policy handling time → the store's 5–7 days (7 business days) applies.
        $this->assertSame($monday->copy()->addWeekdays(7)->toDateString(), $line->fresh()->shipBy()->toDateString());
    }

    public function test_settings_save_keeps_other_shipping_keys_and_validates_ship_from(): void
    {
        $seller = $this->seller(['shipping_settings' => ['processing_days' => '1-2', 'label_format' => '4x6']]);

        Livewire::actingAs($seller)->test(StoreSettings::class)
            ->set('ship_from.line1', '12 Dock St')->set('ship_from.city', 'Austin')->set('ship_from.state', 'Texas')->set('ship_from.postal_code', '7870')
            ->call('save')->assertHasErrors('ship_from.postal_code');

        Livewire::actingAs($seller)->test(StoreSettings::class)
            ->set('ship_from.line1', '12 Dock St')->set('ship_from.city', 'Austin')->set('ship_from.state', 'Texas')->set('ship_from.postal_code', '78701')
            ->set('processing_days', '3-5')->set('return_window_days', 60)->set('return_shipping', 'seller')
            ->call('save')->assertHasNoErrors();

        $p = $seller->sellerProfile->fresh();
        $this->assertSame('4x6', $p->shipping_settings['label_format']);
        $this->assertSame('3-5', $p->shipping_settings['processing_days']);
        $this->assertSame('TX', $p->ship_from['state']);
        $this->assertTrue($p->hasShipFrom());
        $this->assertSame(60, $p->return_window_days);
    }

    public function test_seller_return_window_is_honoured_and_shown(): void
    {
        $seller = $this->seller(['return_window_days' => 60, 'return_shipping' => 'seller']);
        $line = $this->line($seller, OrderStatus::Delivered, ['delivered_at' => now()->subDays(45)]);

        $this->assertTrue($line->fresh()->isWithinReturnWindow(), '45 days in, a 60-day store still accepts returns');
        $this->get(route('products.show', $line->product->slug))->assertOk()->assertSee('60-day returns · free');
    }

    // ================= vacation =================

    public function test_paused_sales_block_checkout(): void
    {
        $seller = $this->seller(['vacation_mode' => true, 'vacation_pause_sales' => true, 'vacation_until' => now()->addDays(5)]);
        $buyer = User::factory()->create();
        $product = $this->product($seller);
        $this->mock(CheckoutPaymentService::class)->shouldReceive('createPaymentIntent')
            ->andReturn(PaymentIntent::constructFrom(['id' => 'pi_x', 'client_secret' => 'x']));
        Cart::firstOrCreate(['user_id' => $buyer->id])->items()->create(['product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($buyer)->post(route('checkout.store'), [
            'name' => 'B', 'line1' => '1 St', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country' => 'US',
        ])->assertSessionHasErrors('name');
        $this->assertSame(0, Order::count());
    }

    public function test_vacation_auto_reply_is_sent_once_and_vacation_ends_on_its_own(): void
    {
        Notification::fake();
        $seller = $this->seller(['vacation_mode' => true, 'vacation_message' => 'Away at a car show.', 'vacation_until' => now()->addDays(3)]);
        $buyer = User::factory()->create();
        $messaging = app(MessagingService::class);
        $c = $messaging->threadFor($buyer, $seller);

        $messaging->send($c, $buyer, 'Is this in stock?');
        $messaging->send($c->fresh(), $buyer, 'Hello?');

        $auto = Conversation::find($c->id)->messages->where('sender_id', $seller->id);
        $this->assertCount(1, $auto);
        $this->assertStringContainsString('Away at a car show.', $auto->first()->body);
        $this->assertNotNull($c->fresh()->awaiting_reply_since, 'an auto-reply is not a real answer');

        $this->travel(4)->days();
        $this->assertFalse($seller->sellerProfile->fresh()->isOnVacation());
        $this->artisan('marketplace:maintain')->assertSuccessful();
        $this->assertFalse((bool) $seller->sellerProfile->fresh()->vacation_mode);
    }

    // ================= team =================

    public function test_team_manager_cannot_promote_themselves_or_hand_out_team_access(): void
    {
        $owner = $this->seller();
        $lead = $this->staff($owner, ['team.manage', 'orders.view']);
        $other = $this->staff($owner, ['orders.view']);
        $leadRow = SellerTeamMember::where('user_id', $lead->id)->first();
        $otherRow = SellerTeamMember::where('user_id', $other->id)->first();

        SellerContext::set($owner, $lead, $leadRow);
        Livewire::actingAs($owner)->test(Team::class)
            ->set('editingId', $leadRow->id)->set('editAbilities', ['team.manage', 'orders.view', 'payouts.view'])
            ->call('saveEdit')->assertStatus(403);

        Livewire::actingAs($owner)->test(Team::class)
            ->set('editingId', $otherRow->id)->set('editRole', 'custom')->set('editAbilities', ['orders.view', 'team.manage'])
            ->call('saveEdit')->assertHasErrors('editAbilities');
        $this->assertNotContains('team.manage', $otherRow->fresh()->abilities);
        SellerContext::clear();
    }

    public function test_resend_and_reactivate_guards(): void
    {
        $owner = $this->seller();
        $active = SellerTeamMember::where('user_id', $this->staff($owner, ['orders.view'])->id)->first();
        $invited = SellerTeamMember::create(['owner_id' => $owner->id, 'email' => 'new@example.com', 'role' => 'staff', 'status' => 'invited']);

        Livewire::actingAs($owner)->test(Team::class)->call('resend', $active->id)->assertStatus(422);
        $this->assertSame('active', $active->fresh()->status, 'resending must not kick an active member out');

        Livewire::actingAs($owner)->test(Team::class)->call('setStatus', $invited->id, 'active')->assertStatus(422);
        $this->assertSame('invited', $invited->fresh()->status);
    }

    // ================= verification =================

    public function test_only_the_owner_can_submit_identity_verification(): void
    {
        $owner = $this->seller();
        $manager = $this->staff($owner, ['settings.manage']);

        SellerContext::set($owner, $manager, SellerTeamMember::where('user_id', $manager->id)->first());
        Livewire::actingAs($owner)->test(Verification::class)
            ->assertSee('Only the store owner can submit')
            ->call('submit')->assertStatus(403);
        SellerContext::clear();
    }

    // ================= API & webhooks =================

    public function test_webhook_urls_must_be_public_https(): void
    {
        $seller = $this->seller();
        WebhookUrlGuard::$resolver = fn ($host) => $host === 'internal.example.com' ? ['10.0.0.5'] : ['93.184.215.14'];

        foreach (['https://10.0.0.5/hook', 'https://localhost/hook', 'https://internal.example.com/hook', 'https://169.254.169.254/latest/meta-data'] as $bad) {
            Livewire::actingAs($seller)->test(ApiAccess::class)->set('hookUrl', $bad)->set('hookEvents', ['order.created'])
                ->call('addEndpoint')->assertHasErrors('hookUrl');
        }
        $this->assertSame(0, WebhookEndpoint::count());

        Livewire::actingAs($seller)->test(ApiAccess::class)->set('hookUrl', 'https://erp.example.com/hook')->set('hookEvents', ['order.created'])
            ->call('addEndpoint')->assertHasNoErrors();
        $this->assertSame(1, WebhookEndpoint::count());
    }

    public function test_delivery_is_blocked_and_logged_if_dns_later_points_inside(): void
    {
        Http::fake();
        $seller = $this->seller();
        $e = WebhookEndpoint::create(['seller_id' => $seller->id, 'url' => 'https://erp.example.com/hook', 'secret' => 'whsec_x', 'events' => ['order.created'], 'is_active' => true]);
        WebhookUrlGuard::$resolver = fn () => ['127.0.0.1'];

        (new DeliverWebhook($e->id, ['event' => 'order.created', 'data' => []]))->handle();

        Http::assertNothingSent();
        $this->assertFalse($e->fresh()->is_active);
        $this->assertSame('blocked', WebhookDelivery::first()->status);
    }

    public function test_send_test_reveal_and_rotate_secret(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $seller = $this->seller();
        $e = WebhookEndpoint::create(['seller_id' => $seller->id, 'url' => 'https://erp.example.com/hook', 'secret' => 'whsec_original_secret_value', 'events' => ['order.created'], 'is_active' => true]);

        $c = Livewire::actingAs($seller)->test(ApiAccess::class)
            ->assertDontSee('whsec_original_secret_value')
            ->call('reveal', $e->id)->assertSee('whsec_original_secret_value')
            ->call('sendTest', $e->id);

        $this->assertTrue(WebhookDelivery::where('event', 'test.ping')->first()->success);

        $c->call('rotateSecret', $e->id);
        $this->assertNotSame('whsec_original_secret_value', $e->fresh()->secret);
    }

    public function test_previously_silent_events_now_fire(): void
    {
        Queue::fake();
        $seller = $this->seller();
        WebhookEndpoint::create(['seller_id' => $seller->id, 'url' => 'https://erp.example.com/hook', 'secret' => 'whsec_x',
            'events' => ['order.delivered', 'return.requested'], 'is_active' => true]);
        $line = $this->line($seller, OrderStatus::Shipped, ['shipped_at' => now()->subDay()]);
        $buyer = $line->order->buyer;

        $this->actingAs($buyer)->post(route('orders.confirm-delivery', $line));
        Queue::assertPushed(DeliverWebhook::class, fn ($j) => $j->payload['event'] === 'order.delivered');

        $this->actingAs($buyer)->post(route('orders.return', $line), ['reason' => 'Wrong part received']);
        Queue::assertPushed(DeliverWebhook::class, fn ($j) => $j->payload['event'] === 'return.requested' && $j->payload['data']['rma_number']);
    }

    public function test_api_is_rate_limited_and_closed_to_suspended_stores(): void
    {
        $seller = $this->seller();
        $token = ApiToken::issue($seller, 'ERP', ['products:read'])['plain'];
        $auth = ['Authorization' => "Bearer {$token}"];

        $this->getJson('/api/v1/products', $auth)->assertOk();

        $seller->sellerProfile->update(['status' => 'suspended']);
        $this->getJson('/api/v1/products', $auth)->assertForbidden();
        $seller->sellerProfile->update(['status' => 'approved']);

        foreach (range(1, 119) as $i) {
            $this->getJson('/api/v1/products', $auth);
        }
        $this->getJson('/api/v1/products', $auth)->assertStatus(429);
    }

    public function test_fulfilment_tools_need_the_fulfilment_permission(): void
    {
        $owner = $this->seller();
        $viewer = $this->staff($owner, ['orders.view']);

        $this->actingAs($viewer)->get(route('seller.fulfilment.pick-list'))->assertForbidden();
        $this->actingAs($owner)->get(route('seller.fulfilment.pick-list'))->assertOk();
    }

    public function test_settings_pages_render(): void
    {
        $seller = $this->seller();
        foreach (['seller.settings.edit', 'seller.team.index', 'seller.verification.index', 'seller.api.index'] as $route) {
            $this->actingAs($seller)->get(route($route))->assertOk();
        }
    }
}
