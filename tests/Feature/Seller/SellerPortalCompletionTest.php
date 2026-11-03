<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Livewire\Seller\CustomerProfile;
use App\Livewire\Seller\Customers;
use App\Livewire\Seller\Questions;
use App\Livewire\Seller\Subscriptions;
use App\Livewire\Seller\Support;
use App\Livewire\Storefront\ProductQuestions;
use App\Models\AdminAlert;
use App\Models\Answer;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Question;
use App\Models\SellerCustomerNote;
use App\Models\SellerProfile;
use App\Models\StockMovement;
use App\Models\Subscription;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\NewProductQuestion;
use App\Notifications\OrderItemCancelled;
use App\Notifications\QuestionAnswered;
use App\Notifications\SupportTicketReplied;
use App\Services\Orders\OrderStockService;
use App\Services\Payments\CheckoutPaymentService;
use App\Services\Payments\RefundService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Stripe\PaymentIntent;
use Tests\TestCase;

class SellerPortalCompletionTest extends TestCase
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

    private function product(User $seller, array $attrs = []): Product
    {
        return Product::create(array_merge([
            'seller_id' => $seller->id,
            'category_id' => Category::create(['name' => 'C', 'slug' => 'c-'.Str::random(6)])->id,
            'title' => 'Brake Pads '.Str::random(4),
            'slug' => 'bp-'.Str::random(8),
            'price' => 50,
            'quantity' => 10,
            'status' => 'active',
        ], $attrs))->fresh();
    }

    private function item(User $seller, ?User $buyer = null, OrderStatus $status = OrderStatus::Paid, array $order = [], array $item = []): OrderItem
    {
        $buyer ??= User::factory()->create();
        $product = $item['product'] ?? $this->product($seller);
        unset($item['product']);

        $o = Order::create(array_merge([
            'order_number' => 'PM-'.strtoupper(Str::random(10)),
            'buyer_id' => $buyer->id,
            'status' => $status === OrderStatus::Placed ? OrderStatus::Placed : OrderStatus::Paid,
            'subtotal' => 100, 'shipping_total' => 0, 'discount_total' => 0, 'credits_used' => 0, 'total' => 100,
            'stripe_payment_intent_id' => 'pi_'.Str::random(10),
            'shipping_address' => ['name' => $buyer->name, 'line1' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country' => 'US'],
            'billing_address' => ['line1' => '1 Main St'],
        ], $order));

        return $o->items()->create(array_merge([
            'seller_id' => $seller->id,
            'product_id' => $product->id,
            'product_title' => $product->title,
            'quantity' => 2,
            'unit_price' => 50,
            'line_total' => 100,
            'commission_rate' => 10,
            'platform_fee_amount' => 10,
            'seller_payout_amount' => 90,
            'status' => $status,
        ], $item));
    }

    // ---------------- stock sync ----------------

    public function test_checkout_takes_ordered_quantity_out_of_stock(): void
    {
        $this->mock(CheckoutPaymentService::class)->shouldReceive('createPaymentIntent')->once()
            ->andReturn(PaymentIntent::constructFrom(['id' => 'pi_stock', 'client_secret' => 'x']));

        $seller = $this->seller();
        $product = $this->product($seller, ['quantity' => 10]);
        $buyer = User::factory()->create();
        Cart::create(['user_id' => $buyer->id])->items()->create(['product_id' => $product->id, 'quantity' => 3]);

        $this->actingAs($buyer)->post(route('checkout.store'), [
            'name' => 'B', 'line1' => '1 St', 'city' => 'X', 'state' => 'TX', 'postal_code' => '78701', 'country' => 'US',
        ])->assertOk();

        $this->assertSame(7, $product->fresh()->quantity);
        $this->assertNotNull(OrderItem::first()->stock_committed_at);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id, 'delta' => -3, 'reason' => 'sale']);
    }

    public function test_variant_stock_is_committed_and_release_is_idempotent(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller);
        $variant = $product->variants()->create(['label' => 'Blue', 'options' => ['Color' => 'Blue'], 'quantity' => 5]);
        $item = $this->item($seller, item: ['product' => $product, 'product_variant_id' => $variant->id, 'quantity' => 2]);

        $stock = app(OrderStockService::class);
        $stock->commit($item->order);
        $stock->commit($item->order); // retried — must not double-deduct
        $this->assertSame(3, $variant->fresh()->quantity);

        $stock->release($item->fresh(), 'test');
        $stock->release($item->fresh(), 'test'); // retried — must not double-restock
        $this->assertSame(5, $variant->fresh()->quantity);
    }

    public function test_failed_payment_puts_stock_back(): void
    {
        config()->set('services.stripe.webhook_secret', 'whsec_test');
        $seller = $this->seller();
        $product = $this->product($seller, ['quantity' => 10]);
        $item = $this->item($seller, status: OrderStatus::Placed, item: ['product' => $product, 'quantity' => 4]);
        app(OrderStockService::class)->commit($item->order);
        $this->assertSame(6, $product->fresh()->quantity);

        $payload = json_encode(['id' => 'evt_1', 'object' => 'event', 'type' => 'payment_intent.payment_failed',
            'data' => ['object' => ['id' => $item->order->stripe_payment_intent_id, 'object' => 'payment_intent']]]);
        $t = time();
        $this->call('POST', route('webhooks.stripe'), [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$payload}", 'whsec_test'),
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertOk();

        // A declined card keeps the stock reserved so the buyer can retry...
        $this->assertSame(OrderStatus::Placed, $item->order->fresh()->status);
        $this->assertSame(6, $product->fresh()->quantity);

        // ...and if they never pay, the unpaid-order sweep puts it back.
        $this->mock(\App\Services\Payments\CheckoutPaymentService::class)->shouldReceive('cancelIntent');
        $this->travel(49)->hours();
        app(\App\Services\Orders\UnpaidOrderService::class)->expireStale();
        $this->assertSame(OrderStatus::Cancelled, $item->order->fresh()->status);
        $this->assertSame(10, $product->fresh()->quantity);
    }

    // ---------------- accept flow ----------------

    public function test_paid_orders_are_listed_as_new_and_can_be_accepted_but_unpaid_cannot(): void
    {
        $seller = $this->seller();
        $paid = $this->item($seller, status: OrderStatus::Paid);
        $unpaid = $this->item($seller, status: OrderStatus::Placed);

        $this->actingAs($seller)->get(route('seller.orders.index', ['status' => 'new']))
            ->assertOk()->assertSee($paid->order->order_number)->assertDontSee($unpaid->order->order_number);

        $this->actingAs($seller)->post(route('seller.orders.accept', $unpaid))->assertStatus(422);
        $this->assertSame(OrderStatus::Placed, $unpaid->fresh()->status);

        $this->actingAs($seller)->post(route('seller.orders.accept', $paid))->assertRedirect();
        $this->assertSame(OrderStatus::SellerAccepted, $paid->fresh()->status);
    }

    // ---------------- cancellation ----------------

    public function test_seller_cancels_a_paid_order_refunds_restocks_and_notifies(): void
    {
        Notification::fake();
        $this->mock(RefundService::class)->shouldReceive('refundAmount')->once()
            ->withArgs(fn ($order, $amount) => abs($amount - 100) < 0.01);

        $seller = $this->seller();
        $product = $this->product($seller, ['quantity' => 8]);
        $item = $this->item($seller, item: ['product' => $product, 'quantity' => 2]);
        app(OrderStockService::class)->commit($item->order);
        $this->assertSame(6, $product->fresh()->quantity);

        $this->actingAs($seller)->post(route('seller.orders.cancel', $item), ['reason' => 'out_of_stock', 'note' => 'Sorry!'])
            ->assertRedirect()->assertSessionHas('success');

        $fresh = $item->fresh();
        $this->assertSame(OrderStatus::Cancelled, $fresh->status);
        $this->assertSame('out_of_stock', $fresh->cancel_reason);
        $this->assertSame(OrderStatus::Cancelled, $item->order->fresh()->status); // its only line
        $this->assertSame(8, $product->fresh()->quantity);
        Notification::assertSentTo($item->order->buyer, OrderItemCancelled::class);
    }

    public function test_wallet_paid_order_is_refunded_to_store_credit(): void
    {
        Notification::fake();
        $this->mock(RefundService::class)->shouldReceive('refundAmount')->withArgs(fn ($o, $amount) => $amount == 0);

        $seller = $this->seller();
        $buyer = User::factory()->create();
        $item = $this->item($seller, $buyer, order: ['stripe_payment_intent_id' => null, 'total' => 0, 'credits_used' => 100]);

        $this->actingAs($seller)->post(route('seller.orders.cancel', $item), ['reason' => 'pricing_error'])->assertRedirect();

        $this->assertEquals(100, (float) $buyer->fresh()->wallet_balance);
    }

    public function test_shipped_orders_and_other_sellers_orders_cannot_be_cancelled(): void
    {
        $seller = $this->seller();
        $shipped = $this->item($seller, status: OrderStatus::Shipped);
        $this->actingAs($seller)->post(route('seller.orders.cancel', $shipped), ['reason' => 'other'])
            ->assertSessionHasErrors('cancel');
        $this->assertSame(OrderStatus::Shipped, $shipped->fresh()->status);

        $other = $this->item($this->seller());
        $this->actingAs($seller)->post(route('seller.orders.cancel', $other), ['reason' => 'other'])->assertForbidden();
    }

    public function test_buyer_requested_cancellations_do_not_hurt_the_cancellation_rate(): void
    {
        Notification::fake();
        $this->mock(RefundService::class)->shouldReceive('refundAmount');
        $seller = $this->seller();
        $item = $this->item($seller);

        $this->actingAs($seller)->post(route('seller.orders.cancel', $item), ['reason' => 'buyer_requested'])->assertRedirect();
        $scorecard = app(\App\Services\Seller\SellerScorecardService::class);
        $this->assertEquals(0, $scorecard->metrics($seller)['cancellation_rate']);

        // ...whereas an out-of-stock cancellation is the seller's fault and does count.
        $second = $this->item($seller);
        $this->actingAs($seller)->post(route('seller.orders.cancel', $second), ['reason' => 'out_of_stock'])->assertRedirect();
        $this->assertGreaterThan(0, $scorecard->metrics($seller)['cancellation_rate']);
    }

    // ---------------- export & invoice ----------------

    public function test_order_export_and_invoice(): void
    {
        $seller = $this->seller();
        $item = $this->item($seller);

        $csv = $this->actingAs($seller)->get(route('seller.orders.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString($item->order->order_number, $csv);

        $this->actingAs($seller)->get(route('seller.orders.invoice', $item->order))->assertOk()->assertSee('Invoice')->assertSee($item->product_title);
        $this->actingAs($this->seller())->get(route('seller.orders.invoice', $item->order))->assertForbidden();
    }

    // ---------------- customers ----------------

    public function test_customers_list_profile_notes_and_messaging(): void
    {
        $seller = $this->seller();
        $buyer = User::factory()->create(['name' => 'Repeat Rita']);
        $this->item($seller, $buyer);
        $this->item($seller, $buyer);
        $stranger = User::factory()->create();

        Livewire::actingAs($seller)->test(Customers::class)
            ->assertSee('Repeat Rita')
            ->set('segment', 'repeat')->assertSee('Repeat Rita')
            ->set('segment', 'lapsed')->assertDontSee('Repeat Rita');

        $this->actingAs($seller)->get(route('seller.customers.show', $buyer))->assertOk()->assertSee('Repeat Rita');
        $this->actingAs($seller)->get(route('seller.customers.show', $stranger))->assertNotFound();

        Livewire::actingAs($seller)->test(CustomerProfile::class, ['buyer' => $buyer])
            ->set('notes', 'Runs a shop')
            ->set('tagsInput', 'Wholesale, #track-day')
            ->call('saveNotes')
            ->assertSet('tagsInput', 'wholesale, track-day');
        $this->assertSame(['wholesale', 'track-day'], SellerCustomerNote::first()->tags);

        Livewire::actingAs($seller)->test(CustomerProfile::class, ['buyer' => $buyer])
            ->call('message')
            ->assertRedirect();
        $this->assertDatabaseHas('conversations', ['seller_id' => $seller->id, 'buyer_id' => $buyer->id]);
    }

    // ---------------- Q&A ----------------

    public function test_question_notifies_seller_and_seller_answer_notifies_asker(): void
    {
        Notification::fake();
        $seller = $this->seller();
        $product = $this->product($seller);
        $shopper = User::factory()->create();

        Livewire::actingAs($shopper)->test(ProductQuestions::class, ['product' => $product])
            ->set('questionBody', 'Does this fit a 2019 Civic?')
            ->call('ask');
        Notification::assertSentTo($seller, NewProductQuestion::class);

        $q = Question::firstOrFail();
        Livewire::actingAs($seller)->test(Questions::class)
            ->assertSee('Does this fit a 2019 Civic?')
            ->set("reply.{$q->id}", 'Yes — direct fit.')
            ->call('answer', $q->id)
            ->assertHasNoErrors();

        $this->assertTrue(Answer::firstOrFail()->is_seller);
        Notification::assertSentTo($shopper, QuestionAnswered::class);

        Livewire::actingAs($seller)->test(Questions::class)->call('toggleHidden', $q->id);
        $this->assertSame('rejected', $q->fresh()->status);
    }

    public function test_seller_cannot_answer_questions_on_other_sellers_products(): void
    {
        $seller = $this->seller();
        $q = Question::create(['product_id' => $this->product($this->seller())->id, 'body' => 'Fits?', 'status' => 'published']);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::actingAs($seller)->test(Questions::class)->set("reply.{$q->id}", 'Hi')->call('answer', $q->id);
    }

    // ---------------- subscriptions ----------------

    public function test_seller_controls_subscribe_and_save_and_sees_subscribers(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller, ['subscription_enabled' => true, 'subscription_discount_pct' => 12]);
        $buyer = User::factory()->create(['name' => 'Sub Sam']);

        $this->actingAs($buyer)->post(route('subscriptions.store'), ['product_id' => $product->id, 'quantity' => 2, 'interval_days' => 30])->assertRedirect();
        $sub = Subscription::firstOrFail();
        $this->assertEquals(12, (float) $sub->discount_pct);

        $sub->update(['next_run_at' => now()->addDays(3)]);
        Livewire::actingAs($seller)->test(Subscriptions::class)
            ->assertSee('Sub Sam')->assertSee('Upcoming repeat orders');

        $off = $this->product($seller, ['subscription_enabled' => false]);
        $this->actingAs(User::factory()->create())->post(route('subscriptions.store'), ['product_id' => $off->id, 'quantity' => 1, 'interval_days' => 30])->assertStatus(422);
    }

    // ---------------- support ----------------

    public function test_support_ticket_round_trip_between_seller_and_admin(): void
    {
        Notification::fake();
        $seller = $this->seller();
        $item = $this->item($seller);

        Livewire::actingAs($seller)->test(Support::class)
            ->set('composing', true)
            ->set('category', 'payouts')
            ->set('subject', 'Payout missing')
            ->set('body', 'My payout from last week has not arrived yet.')
            ->set('orderNumber', $item->order->order_number)
            ->call('submit')
            ->assertHasNoErrors();

        $ticket = SupportTicket::firstOrFail();
        $this->assertSame('high', $ticket->priority);
        $this->assertSame($item->id, $ticket->order_item_id);
        $this->assertSame(1, AdminAlert::where('type', 'support_ticket')->count());

        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $this->withTwoFactor($admin);
        $this->actingAs($admin)->get(route('admin.support.index'))->assertOk()->assertSee('Payout missing');
        $this->actingAs($admin)->post(route('admin.support.reply', $ticket), ['body' => 'Sent today.'])->assertRedirect();

        $this->assertSame('answered', $ticket->fresh()->status);
        Notification::assertSentTo($seller, SupportTicketReplied::class);

        Livewire::actingAs($seller)->test(Support::class, ['ticketId' => $ticket->id])
            ->set('reply', 'Thanks!')->call('sendReply');
        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertSame(3, $ticket->messages()->count());
    }

    // ---------------- every new page renders ----------------

    public function test_all_new_pages_render(): void
    {
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $this->item($seller, $buyer);

        foreach (['seller.customers.index', 'seller.subscriptions.index', 'seller.questions.index', 'seller.support.index', 'seller.orders.index', 'seller.dashboard'] as $route) {
            $this->actingAs($seller)->get(route($route))->assertOk();
        }
        foreach (['new', 'unpaid', 'returns', 'cancelled'] as $tab) {
            $this->actingAs($seller)->get(route('seller.orders.index', ['status' => $tab]))->assertOk();
        }
    }
}
