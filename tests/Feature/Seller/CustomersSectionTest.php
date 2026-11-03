<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Livewire\Seller\CustomerProfile;
use App\Livewire\Seller\Customers;
use App\Livewire\Seller\Inbox;
use App\Livewire\Seller\Questions;
use App\Livewire\Storefront\BuyerMessages;
use App\Livewire\Storefront\ProductQuestions;
use App\Models\Answer;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Conversation;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Question;
use App\Models\SellerBlockedBuyer;
use App\Models\SellerProfile;
use App\Models\SellerSavedReply;
use App\Models\SellerTeamMember;
use App\Models\User;
use App\Notifications\NewMessageReceived;
use App\Services\Marketing\CouponService;
use App\Services\MessagingService;
use App\Services\Payments\CheckoutPaymentService;
use App\Services\Seller\OfferService;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Stripe\PaymentIntent;
use Tests\TestCase;

class CustomersSectionTest extends TestCase
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
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'Torque Shop', 'business_type' => 'llc', 'status' => 'approved', 'slug' => 's-'.uniqid()]);

        return $this->withTwoFactor($u);
    }

    private function product(User $seller, array $extra = []): Product
    {
        return Product::create(array_merge([
            'seller_id' => $seller->id,
            'category_id' => Category::create(['name' => 'C', 'slug' => 'c-'.Str::random(6)])->id,
            'title' => 'Brake Kit '.Str::random(4), 'slug' => 'p-'.Str::random(8),
            'price' => 100, 'quantity' => 10, 'status' => 'active', 'accepts_offers' => true,
        ], $extra))->fresh();
    }

    private function item(User $seller, User $buyer, array $extra = []): OrderItem
    {
        $product = $extra['product'] ?? $this->product($seller);
        unset($extra['product']);
        $o = Order::create([
            'order_number' => 'PM-'.strtoupper(Str::random(10)), 'buyer_id' => $buyer->id, 'status' => OrderStatus::Paid,
            'subtotal' => 100, 'shipping_total' => 0, 'discount_total' => 0, 'credits_used' => 0, 'total' => 100,
            'shipping_address' => ['line1' => '1 St', 'city' => 'Austin', 'state' => 'TX'], 'billing_address' => ['line1' => '1 St'], 'placed_at' => now(),
        ]);

        return $o->items()->create(array_merge([
            'seller_id' => $seller->id, 'product_id' => $product->id, 'product_title' => $product->title,
            'quantity' => 1, 'unit_price' => 100, 'line_total' => 100, 'commission_rate' => 10,
            'platform_fee_amount' => 10, 'seller_payout_amount' => 90, 'status' => OrderStatus::Shipped,
        ], $extra));
    }

    private function thread(User $seller, User $buyer, ?Product $product = null): Conversation
    {
        return app(MessagingService::class)->threadFor($buyer, $seller, $product);
    }

    // ================= messages =================

    public function test_needs_reply_clock_starts_on_buyer_message_and_stops_on_seller_reply(): void
    {
        Notification::fake();
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $messaging = app(MessagingService::class);
        $c = $this->thread($seller, $buyer);

        $messaging->send($c, $buyer, 'Does this fit a 2015 F-150?');
        $first = $c->fresh()->awaiting_reply_since;
        $this->assertNotNull($first);

        $this->travel(2)->hours();
        $messaging->send($c->fresh(), $buyer, 'Hello?');
        $this->assertEquals($first->timestamp, $c->fresh()->awaiting_reply_since->timestamp, 'clock keeps the oldest unanswered message');

        Livewire::actingAs($seller)->test(Inbox::class)->set('filter', 'needs_reply')->assertSee($buyer->name);

        $messaging->send($c->fresh(), $seller, 'Yes it does.');
        $this->assertNull($c->fresh()->awaiting_reply_since);
        Livewire::actingAs($seller)->test(Inbox::class)->set('filter', 'needs_reply')->assertDontSee($buyer->name);
    }

    public function test_only_the_first_unread_message_is_emailed(): void
    {
        Notification::fake();
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $messaging = app(MessagingService::class);
        $c = $this->thread($seller, $buyer);

        $messaging->send($c, $buyer, 'one');
        $messaging->send($c->fresh(), $buyer, 'two');

        $sent = Notification::sent($seller, NewMessageReceived::class);
        $this->assertCount(2, $sent);
        $this->assertSame(['mail', 'database'], $sent[0]->via($seller));
        $this->assertSame(['database'], $sent[1]->via($seller));

        // once read, the next message emails again
        $c->fresh()->markReadFor('seller');
        $messaging->send($c->fresh(), $buyer, 'three');
        $this->assertSame(['mail', 'database'], Notification::sent($seller, NewMessageReceived::class)[2]->via($seller));
    }

    public function test_seller_reply_with_photos_and_read_receipts(): void
    {
        Notification::fake();
        Storage::fake('public');
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $c = $this->thread($seller, $buyer);
        app(MessagingService::class)->send($c, $buyer, 'Photo of my old part?');

        Livewire::actingAs($seller)->test(Inbox::class, ['activeId' => $c->id])
            ->set('photos', [UploadedFile::fake()->image('part.jpg')])
            ->call('reply')
            ->assertHasNoErrors();

        $msg = $c->messages()->reorder()->latest('id')->first();
        $this->assertSame('Sent a photo', $msg->body);
        $this->assertCount(1, $msg->attachments);
        Storage::disk('public')->assertExists($msg->attachments[0]['path']);
        $this->assertNull($msg->read_at);

        // buyer opens the thread → seller's message is marked seen
        Livewire::actingAs($buyer)->test(BuyerMessages::class, ['activeId' => $c->id])->assertSee('Sent a photo');
        $this->assertNotNull($msg->fresh()->read_at);

        // empty message with no photo is rejected
        Livewire::actingAs($seller)->test(Inbox::class, ['activeId' => $c->id])->set('body', '')->call('reply')->assertHasErrors('body');
    }

    public function test_staff_without_messages_permission_cannot_open_inbox(): void
    {
        $seller = $this->seller();
        $staff = $this->withTwoFactor(User::factory()->create(['email' => 'pack@example.com']));
        SellerTeamMember::create([
            'owner_id' => $seller->id, 'user_id' => $staff->id, 'email' => 'pack@example.com',
            'role' => 'viewer', 'abilities' => ['orders.view'], 'status' => 'active',
        ]);

        $this->actingAs($staff)->get(route('seller.messages.index'))->assertForbidden();
        $this->actingAs($seller)->get(route('seller.messages.index'))->assertOk();
    }

    public function test_saved_replies_fill_placeholders_from_the_order(): void
    {
        Notification::fake();
        $seller = $this->seller();
        $buyer = User::factory()->create(['name' => 'Jamie Rivera']);
        $item = $this->item($seller, $buyer, ['carrier' => 'UPS', 'tracking_number' => '1Z999AA10123456784']);
        $c = app(MessagingService::class)->threadFor($buyer, $seller, null, $item->order);
        app(MessagingService::class)->send($c, $buyer, 'Where is it?');

        $component = Livewire::actingAs($seller)->test(Inbox::class, ['activeId' => $c->id])->call('addStarterReplies');
        $this->assertSame(count(SellerSavedReply::STARTERS), SellerSavedReply::where('seller_id', $seller->id)->count());

        $shipped = SellerSavedReply::where('seller_id', $seller->id)->where('title', 'Shipped + tracking')->first();
        $component->call('useReply', $shipped->id);
        $body = $component->get('body');
        $this->assertStringContainsString('Jamie', $body);
        $this->assertStringContainsString($item->order->order_number, $body);
        $this->assertStringContainsString('1Z999AA10123456784', $body);
        $this->assertStringNotContainsString('{', $body);
        $this->assertSame(1, $shipped->fresh()->use_count);

        // another seller can't use (or see) these
        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($this->seller())->test(Inbox::class)->call('editReply', $shipped->id);
    }

    public function test_unknown_placeholders_are_removed_cleanly(): void
    {
        $this->assertSame('Hi, your order shipped.', SellerSavedReply::interpolate('Hi {buyer_first_name}, your order {order_number} shipped.', ['{order_number}' => '']));
    }

    public function test_mark_handled_and_mark_unread(): void
    {
        Notification::fake();
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $c = $this->thread($seller, $buyer);
        app(MessagingService::class)->send($c, $buyer, 'Thanks, all sorted on the phone.');

        Livewire::actingAs($seller)->test(Inbox::class, ['activeId' => $c->id])->call('markHandled')->call('markUnread');
        $c->refresh();
        $this->assertNull($c->awaiting_reply_since);
        $this->assertSame(1, (int) $c->seller_unread);
    }

    public function test_response_stats(): void
    {
        Notification::fake();
        $seller = $this->seller();
        $messaging = app(MessagingService::class);

        // answered in 2h
        $a = $this->thread($seller, User::factory()->create());
        $messaging->send($a, $a->buyer, 'q1');
        $this->travel(2)->hours();
        $messaging->send($a->fresh(), $seller, 'a1');

        // never answered, now 30h old → overdue and counts against the rate
        $b = $this->thread($seller, User::factory()->create());
        $messaging->send($b, $b->buyer, 'q2');
        $this->travel(30)->hours();

        $stats = $messaging->responseStats($seller->id);
        $this->assertSame(1, $stats['needs_reply']);
        $this->assertSame(1, $stats['overdue']);
        $this->assertEquals(2.0, $stats['avg_first_response_hours']);
        $this->assertEquals(50.0, $stats['within_target_rate']);
        $this->assertTrue($b->fresh()->isOverdue());
    }

    public function test_buyer_can_contact_seller_from_order_and_reopen_archived_thread(): void
    {
        Notification::fake();
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $item = $this->item($seller, $buyer);

        $this->actingAs(User::factory()->create())->post(route('orders.contact-seller', $item))->assertForbidden();

        $res = $this->actingAs($buyer)->post(route('orders.contact-seller', $item));
        $c = Conversation::where('buyer_id', $buyer->id)->firstOrFail();
        $res->assertRedirect(route('messages.index', ['c' => $c->id]));
        $this->assertSame($item->order_id, $c->order_id);

        // same order → same thread
        $this->actingAs($buyer)->post(route('orders.contact-seller', $item));
        $this->assertSame(1, Conversation::where('buyer_id', $buyer->id)->count());

        // empty thread isn't cluttering the seller's inbox
        Livewire::actingAs($seller)->test(Inbox::class)->assertDontSee($buyer->name);

        // seller archives; buyer replying reopens it
        $c->update(['closed' => true]);
        Livewire::actingAs($buyer)->test(BuyerMessages::class, ['activeId' => $c->id])->set('body', 'Any update?')->call('reply')->assertHasNoErrors();
        $this->assertFalse($c->fresh()->closed);
        Livewire::actingAs($seller)->test(Inbox::class)->assertSee($buyer->name);
    }

    // ================= blocking =================

    public function test_blocked_buyer_cannot_message_offer_ask_or_check_out(): void
    {
        Notification::fake();
        $seller = $this->seller();
        $buyer = $this->withTwoFactor(User::factory()->create());
        $product = $this->product($seller);
        $c = $this->thread($seller, $buyer, $product);
        app(MessagingService::class)->send($c, $buyer, 'hi');

        Livewire::actingAs($seller)->test(Inbox::class, ['activeId' => $c->id])->set('blockReason', 'abusive')->call('toggleBlock');
        $this->assertTrue(SellerBlockedBuyer::isBlocked($seller->id, $buyer->id));
        $this->assertTrue($c->fresh()->closed);

        // messages
        Livewire::actingAs($buyer)->test(BuyerMessages::class, ['activeId' => $c->id])
            ->assertSee("isn't accepting messages", false);
        try {
            app(MessagingService::class)->send($c->fresh(), $buyer, 'please');
            $this->fail('blocked buyer could message');
        } catch (ValidationException) {
        }

        // offers
        try {
            app(OfferService::class)->create($buyer, $product, 80);
            $this->fail('blocked buyer could make an offer');
        } catch (ValidationException $e) {
            $this->assertStringContainsString("isn't accepting offers", $e->getMessage());
        }

        // questions
        Livewire::actingAs($buyer)->test(ProductQuestions::class, ['product' => $product])
            ->set('questionBody', 'Does this fit a Civic?')->call('ask')->assertHasErrors('questionBody');
        $this->assertSame(0, Question::count());

        // checkout
        $this->mock(CheckoutPaymentService::class)->shouldReceive('createPaymentIntent')
            ->andReturn(PaymentIntent::constructFrom(['id' => 'pi_x', 'client_secret' => 'x']));
        Cart::firstOrCreate(['user_id' => $buyer->id])->items()->create(['product_id' => $product->id, 'quantity' => 1]);
        $this->actingAs($buyer)->post(route('checkout.store'), [
            'name' => 'B', 'line1' => '1 St', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country' => 'US',
        ])->assertSessionHasErrors('name');
        $this->assertSame(0, Order::count());

        // unblock restores messaging
        Livewire::actingAs($seller)->test(Inbox::class, ['activeId' => $c->id])->call('toggleBlock');
        app(MessagingService::class)->send($c->fresh(), $buyer, 'thanks');
        $this->assertSame(2, $c->messages()->count());
    }

    // ================= customers =================

    public function test_private_coupon_is_locked_to_the_customer_and_delivered_by_message(): void
    {
        Notification::fake();
        $seller = $this->seller();
        $buyer = User::factory()->create(['name' => 'Sam Lee']);
        $item = $this->item($seller, $buyer);

        Livewire::actingAs($seller)->test(CustomerProfile::class, ['buyer' => $buyer])
            ->set('couponType', 'percentage')->set('couponValue', '15')->set('couponDays', 14)
            ->call('sendCoupon')->assertHasNoErrors();

        $coupon = Coupon::where('user_id', $buyer->id)->firstOrFail();
        $this->assertSame($seller->id, $coupon->seller_id);
        $this->assertSame(1, (int) $coupon->usage_limit);
        $this->assertEquals(15, (float) $coupon->value);

        $msg = Conversation::where('buyer_id', $buyer->id)->firstOrFail()->messages()->first();
        $this->assertStringContainsString($coupon->code, $msg->body);
        $this->assertStringContainsString('15% off', $msg->body);

        $line = ['product' => $item->product, 'quantity' => 1, 'line_total' => 100.0];
        $coupons = app(CouponService::class);
        $this->assertTrue($coupons->validate($coupon->code, $buyer, 100, [$line])['valid']);
        $this->assertFalse($coupons->validate($coupon->code, User::factory()->create(), 100, [$line])['valid']);

        // out-of-range discount rejected
        Livewire::actingAs($seller)->test(CustomerProfile::class, ['buyer' => $buyer])
            ->set('couponValue', '90')->call('sendCoupon')->assertHasErrors('couponValue');
    }

    public function test_block_from_profile_and_segments(): void
    {
        $seller = $this->seller();
        $once = User::factory()->create(['name' => 'Once Buyer']);
        $twice = User::factory()->create(['name' => 'Twice Buyer']);
        $this->item($seller, $once);
        $this->item($seller, $twice);
        $this->item($seller, $twice);

        Livewire::actingAs($seller)->test(Customers::class)->set('segment', 'one_time')
            ->assertSee('Once Buyer')->assertDontSee('Twice Buyer');

        Livewire::actingAs($seller)->test(CustomerProfile::class, ['buyer' => $twice])->call('toggleBlock');
        $this->assertTrue(SellerBlockedBuyer::isBlocked($seller->id, $twice->id));

        Livewire::actingAs($seller)->test(Customers::class)->set('segment', 'blocked')
            ->assertSee('Twice Buyer')->assertDontSee('Once Buyer');
    }

    // ================= Q&A =================

    public function test_seller_can_edit_answers_and_use_saved_replies(): void
    {
        Notification::fake();
        $seller = $this->seller();
        $product = $this->product($seller);
        $asker = User::factory()->create(['name' => 'Alex Kim']);
        $q = Question::create(['product_id' => $product->id, 'user_id' => $asker->id, 'body' => 'In stock?', 'status' => 'published']);
        $saved = SellerSavedReply::create(['seller_id' => $seller->id, 'title' => 'Stock', 'body' => 'Hi {buyer_first_name}, {product_title} ships today.']);

        $c = Livewire::actingAs($seller)->test(Questions::class)->call('useReply', $q->id, $saved->id);
        $this->assertSame("Hi Alex, {$product->title} ships today.", $c->get('reply')[$q->id]);
        $c->call('answer', $q->id)->assertHasNoErrors();

        $answer = Answer::where('question_id', $q->id)->firstOrFail();
        $c->call('editAnswer', $answer->id)->set('editBody', 'Yes — 10 in stock, ships today.')->call('updateAnswer')->assertHasNoErrors();
        $this->assertSame('Yes — 10 in stock, ships today.', $answer->fresh()->body);

        // another seller can't touch it
        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($this->seller())->test(Questions::class)->call('editAnswer', $answer->id);
    }

    public function test_questions_product_filter_and_overdue_count(): void
    {
        $seller = $this->seller();
        $a = $this->product($seller, ['title' => 'Alpha Rotor']);
        $b = $this->product($seller, ['title' => 'Bravo Caliper']);
        Question::create(['product_id' => $a->id, 'author_name' => 'G', 'body' => 'Question about alpha?', 'status' => 'published']);
        $this->travel(-3)->days();
        Question::create(['product_id' => $b->id, 'author_name' => 'G', 'body' => 'Question about bravo?', 'status' => 'published']);
        $this->travelBack();

        Livewire::actingAs($seller)->test(Questions::class)
            ->assertViewHas('stats', fn ($s) => $s['overdue'] === 1)
            ->set('productId', (string) $a->id)
            ->assertSee('Question about alpha?')->assertDontSee('Question about bravo?');
    }

    public function test_customers_pages_render(): void
    {
        Notification::fake();
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $this->item($seller, $buyer);
        $c = $this->thread($seller, $buyer);
        app(MessagingService::class)->send($c, $buyer, 'hello there');

        // Buyer first: once a seller-portal request runs, route() builds URLs on the seller host.
        $this->actingAs($buyer)->get(route('messages.index', ['c' => $c->id]))->assertOk()->assertSee('hello there');
        $this->actingAs($seller)->get(route('seller.messages.index', ['c' => $c->id]))->assertOk()->assertSee('hello there')->assertSee('Spent with you');
        $this->actingAs($seller)->get(route('seller.customers.show', $buyer))->assertOk()->assertSee('Send a private coupon');
        $this->actingAs($seller)->get(route('seller.questions.index'))->assertOk();
    }
}
