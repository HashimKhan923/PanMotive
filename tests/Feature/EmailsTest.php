<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Notifications\NewOrderReceived;
use App\Notifications\OrderConfirmation;
use App\Notifications\OrderDelivered;
use App\Notifications\OrderPaymentReminder;
use App\Notifications\PriceDropAlert;
use App\Notifications\SellerApproved;
use App\Notifications\UnpaidOrderCancelled;
use App\Notifications\WelcomeToPanmotive;
use App\Services\Orders\OrderPaidNotifier;
use App\Services\Orders\UnpaidOrderService;
use App\Support\EmailPreferences;
use App\Support\EmailPreviews;
use Database\Seeders\AdminPermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** The whole email system: who gets what, once, what it says, and how people turn emails off. */
class EmailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seller(string $store = 'Lee Performance'): User
    {
        $u = User::factory()->create(['name' => 'Jordan Lee']);
        $u->assignRole('seller');
        SellerProfile::create(['user_id' => $u->id, 'business_name' => $store.' '.uniqid(), 'business_type' => 'llc', 'status' => 'approved', 'slug' => 's-'.uniqid()]);

        return $this->withTwoFactor($u);
    }

    private function line(Order $order, User $seller, float $price, string $title = null): OrderItem
    {
        $product = Product::create([
            'seller_id' => $seller->id, 'category_id' => Category::firstOrCreate(['slug' => 'c'], ['name' => 'C'])->id,
            'title' => $title ?? 'Part '.Str::random(4), 'slug' => 'p-'.Str::random(8), 'price' => $price, 'quantity' => 10, 'status' => 'active',
        ]);

        return $order->items()->create([
            'seller_id' => $seller->id, 'product_id' => $product->id, 'product_title' => $product->title, 'quantity' => 1,
            'unit_price' => $price, 'line_total' => $price, 'commission_rate' => 10,
            'platform_fee_amount' => round($price * .1, 2), 'seller_payout_amount' => round($price * .9, 2), 'status' => OrderStatus::Paid,
        ]);
    }

    private function order(array $attrs = []): Order
    {
        $address = ['name' => 'Alex Rivera', 'line1' => '1450 Elm St', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country' => 'US'];

        return Order::create(array_merge([
            'order_number' => 'PM-'.strtoupper(Str::random(10)), 'buyer_id' => User::factory()->create(['name' => 'Alex Rivera'])->id,
            'status' => OrderStatus::Paid, 'subtotal' => 150, 'shipping_total' => 0, 'discount_total' => 0, 'credits_used' => 0, 'total' => 150,
            'shipping_address' => $address, 'billing_address' => $address, 'placed_at' => now(), 'stripe_payment_intent_id' => 'pi_'.Str::random(6),
        ], $attrs));
    }

    public function test_a_paid_order_sends_one_receipt_and_each_seller_their_own_lines(): void
    {
        Notification::fake();
        $a = $this->seller('Store A');
        $b = $this->seller('Store B');
        $order = $this->order();
        $this->line($order, $a, 100, 'Brake pads');
        $this->line($order, $b, 50, 'Air filter');

        app(OrderPaidNotifier::class)->notify($order);
        app(OrderPaidNotifier::class)->notify($order->fresh()); // Stripe repeats webhooks

        Notification::assertSentToTimes($order->buyer, OrderConfirmation::class, 1);
        Notification::assertSentToTimes($a, NewOrderReceived::class, 1);
        Notification::assertSentToTimes($b, NewOrderReceived::class, 1);
        $this->assertNotNull($order->fresh()->confirmation_sent_at);

        // Each seller's email lists only their own items and what they'll earn.
        $html = (string) (new NewOrderReceived($order))->toMail($a)->render();
        $this->assertStringContainsString('Brake pads', $html);
        $this->assertStringNotContainsString('Air filter', $html);
        $this->assertStringContainsString('$90.00', $html);
        $this->assertContains('mail', (new NewOrderReceived($order))->via($a));
    }

    public function test_guests_get_their_receipt_by_email(): void
    {
        Notification::fake();
        $order = $this->order(['buyer_id' => null, 'guest_email' => 'guest@example.com', 'guest_name' => 'Gus']);
        $this->line($order, $this->seller(), 40);

        app(OrderPaidNotifier::class)->notify($order);

        Notification::assertSentOnDemand(OrderConfirmation::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'guest@example.com');
    }

    public function test_the_receipt_shows_the_order_and_the_brand_footer(): void
    {
        $order = $this->order(['discount_total' => 10, 'total' => 140]);
        $this->line($order, $this->seller(), 150, 'Bosch brake pads');

        $mail = (new OrderConfirmation($order))->toMail($order->buyer);
        $html = (string) $mail->render();

        $this->assertSame("Order confirmed: {$order->order_number}", $mail->subject);
        foreach (['Hi Alex,', $order->order_number, 'Bosch brake pads', 'Total paid', '$140.00', '1450 Elm St', 'View your order',
            'support@panmotive.com', 'Panmotive-primary-reverse.png', 'Ordered', 'Shipped', 'Delivered'] as $text) {
            $this->assertStringContainsString($text, $html, "missing: {$text}");
        }
        $this->assertStringNotContainsString('Laravel', $html);
        $this->assertStringNotContainsString('&lt;table', $html); // blocks render as HTML, not escaped text

        $this->assertSame('notifications@panmotive.com', config('mail.from.address'));
        $this->assertSame('support@panmotive.com', config('mail.reply_to.address'));
    }

    public function test_optional_emails_respect_the_switch_and_the_stop_link_works(): void
    {
        $buyer = User::factory()->create();
        $product = Product::create(['seller_id' => $this->seller()->id, 'category_id' => Category::create(['name' => 'X', 'slug' => 'x'])->id,
            'title' => 'Filter', 'slug' => 'filter', 'price' => 20, 'quantity' => 1, 'status' => 'active']);
        $drop = new PriceDropAlert($product, 30, 20);

        $this->assertContains('mail', $drop->via($buyer));
        $html = (string) $drop->toMail($buyer)->render();
        $this->assertStringContainsString('Stop price drops emails', $html);

        $url = EmailPreferences::stopUrl($buyer, 'price_drops');
        $this->get($url)->assertOk()->assertSee('Stop price drops emails?');
        $this->assertTrue(EmailPreferences::wants($buyer->fresh(), 'price_drops')); // a GET (link scanner) changes nothing

        $this->post($url)->assertOk()->assertSee('Price drops emails are off');
        $this->assertFalse(EmailPreferences::wants($buyer->fresh(), 'price_drops'));
        $this->assertNotContains('mail', $drop->via($buyer->fresh()));
        $this->assertContains('database', $drop->via($buyer->fresh())); // the in-app alert stays

        // Gmail's one-click unsubscribe, and turning it back on.
        $this->post($url, ['action' => 'resume'])->assertOk();
        $this->assertTrue(EmailPreferences::wants($buyer->fresh(), 'price_drops'));
        $this->post($url, ['List-Unsubscribe' => 'One-Click'])->assertNoContent();
        $this->assertFalse(EmailPreferences::wants($buyer->fresh(), 'price_drops'));

        // Tampered or made-up links are refused.
        $this->post(str_replace('/'.$buyer->id.'/', '/'.($buyer->id + 1).'/', $url))->assertForbidden();
        $this->get(URL::signedRoute('email.stop', ['user' => $buyer->id, 'category' => 'receipts']))->assertNotFound();
    }

    public function test_the_preferences_page_lists_and_toggles_email_types(): void
    {
        $buyer = User::factory()->create();

        $this->actingAs($buyer)->get(route('marketing.preferences'))->assertOk()
            ->assertSee('Price drops')->assertSee('Answers to your questions')->assertDontSee('Payouts');

        \Livewire\Livewire::actingAs($buyer)->test(\App\Livewire\Storefront\MarketingPreferences::class)
            ->call('toggleEmail', 'questions');
        $this->assertSame(['questions'], $buyer->fresh()->email_opt_outs);

        // Seller-only switches can't be set by buyers.
        \Livewire\Livewire::actingAs($buyer)->test(\App\Livewire\Storefront\MarketingPreferences::class)
            ->call('toggleEmail', 'payouts')->assertNotFound();
    }

    public function test_unpaid_orders_get_one_reminder_then_a_cancellation_email(): void
    {
        Notification::fake();
        config(['marketplace.unpaid_order_hours' => 48]);
        $order = $this->order(['status' => OrderStatus::Placed]);
        $this->line($order, $this->seller(), 30)->update(['status' => OrderStatus::Placed]);
        $order->forceFill(['created_at' => now()->subHours(30)])->save();

        $service = app(UnpaidOrderService::class);
        $this->assertSame(1, $service->remindUnpaid());
        $this->assertSame(0, $service->remindUnpaid());
        Notification::assertSentToTimes($order->buyer, OrderPaymentReminder::class, 1);

        $order->forceFill(['created_at' => now()->subHours(49)])->save();
        $service->expireStale();
        Notification::assertSentTo($order->buyer, UnpaidOrderCancelled::class);
    }

    public function test_a_carrier_delivery_scan_emails_the_buyer_once_per_package(): void
    {
        Notification::fake();
        \Illuminate\Support\Facades\Queue::fake();
        $order = $this->order();
        $seller = $this->seller();
        foreach ([1, 2] as $i) {
            $this->line($order, $seller, 20)->update(['status' => OrderStatus::Shipped, 'tracking_number' => 'TRK1', 'carrier' => 'UPS', 'shipped_at' => now()]);
        }

        config(['services.easypost.webhook_secret' => 'the-real-secret']);
        $body = json_encode(['description' => 'tracker.updated', 'result' => ['tracking_code' => 'TRK1', 'status' => 'delivered']]);
        $this->call('POST', route('webhooks.easypost'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HMAC_SIGNATURE' => 'hmac-sha256-hex='.hash_hmac('sha256', $body, 'the-real-secret'),
        ], $body)->assertOk();

        Notification::assertSentToTimes($order->buyer, OrderDelivered::class, 1);
    }

    public function test_account_emails_welcome_reset_and_seller_decisions(): void
    {
        Notification::fake();
        $user = User::factory()->create(['name' => 'Sam Ortiz']);

        event(new Registered($user));
        Notification::assertSentTo($user, WelcomeToPanmotive::class);

        $reset = (new ResetPassword('tok123'))->toMail($user);
        $this->assertSame('Reset your Panmotive password', $reset->subject);
        $this->assertStringContainsString('tok123', (string) $reset->render());
        $this->assertStringContainsString('Hi Sam,', (string) $reset->render());

        $seller = $this->seller();
        $this->assertSame(['mail', 'database'], (new SellerApproved)->via($seller));
        $this->assertStringContainsString(route('seller.dashboard'), (string) (new SellerApproved)->toMail($seller)->render());
    }

    public function test_mail_goes_on_the_queue_but_the_in_app_bell_stays_instant(): void
    {
        $n = new OrderConfirmation($this->order());
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $n);
        $this->assertSame('sync', $n->viaConnections()['database']);
        $this->assertSame(config('queue.default'), $n->viaConnections()['mail']);
    }

    public function test_admin_can_preview_every_email_without_leaving_data_behind(): void
    {
        $this->seed(AdminPermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $admin = $this->withTwoFactor($admin);

        $this->actingAs($admin)->get(route('admin.emails.index'))->assertOk()
            ->assertSee('Order confirmation (receipt)')->assertSee('notifications@panmotive.com')->assertSee('Emails are not being delivered yet');

        $users = User::count();
        $orders = Order::count();
        foreach (array_keys(EmailPreviews::catalog()) as $key) {
            $this->actingAs($admin)->get(route('admin.emails.preview', $key))->assertOk()->assertSee('Panmotive');
        }
        $this->assertSame($users, User::count());
        $this->assertSame($orders, Order::count());

        $this->actingAs($admin)->post(route('admin.emails.test', 'welcome'))->assertRedirect()->assertSessionHas('success');
        $this->actingAs($admin)->get(route('admin.emails.preview', 'nope'))->assertNotFound();

        // Sellers and buyers can't open it.
        $this->actingAs($this->seller())->get(route('admin.emails.index'))->assertForbidden();
    }

    public function test_the_shop_footer_shows_the_support_address(): void
    {
        $this->get(config('app.url').'/')->assertOk()->assertSee('support@panmotive.com');
    }
}
