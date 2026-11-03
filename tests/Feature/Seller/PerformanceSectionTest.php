<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Livewire\Seller\AccountHealth;
use App\Livewire\Seller\Reviews;
use App\Models\AdminAlert;
use App\Models\Answer;
use App\Models\BuyerClaim;
use App\Models\Category;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductView;
use App\Models\Question;
use App\Models\ReturnRequest;
use App\Models\Review;
use App\Models\SaleEvent;
use App\Models\SellerProfile;
use App\Models\SellerSavedReply;
use App\Models\SellerTeamMember;
use App\Models\User;
use App\Notifications\ClaimResolvedBySeller;
use App\Notifications\SellerRespondedToReview;
use App\Services\MessagingService;
use App\Services\Payments\RefundService;
use App\Services\Seller\AccountHealthService;
use App\Services\Seller\InsightsService;
use App\Services\Seller\SellerAnalyticsService;
use App\Services\Seller\SellerScorecardService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PerformanceSectionTest extends TestCase
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
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'Brake Barn', 'business_type' => 'llc', 'status' => 'approved', 'slug' => 's-'.uniqid()]);

        return $this->withTwoFactor($u);
    }

    private function product(User $seller, array $extra = []): Product
    {
        return Product::create(array_merge([
            'seller_id' => $seller->id,
            'category_id' => Category::create(['name' => 'Brakes', 'slug' => 'c-'.Str::random(6)])->id,
            'title' => 'Ceramic Pads '.Str::random(4), 'slug' => 'p-'.Str::random(8),
            'price' => 100, 'quantity' => 10, 'status' => 'active',
        ], $extra))->fresh();
    }

    private function line(User $seller, ?User $buyer, OrderStatus $status, float $total = 100, array $item = [], ?Carbon $at = null): OrderItem
    {
        $at ??= now();
        $product = $item['product'] ?? $this->product($seller);
        unset($item['product']);
        $order = Order::create([
            'order_number' => 'PM-'.strtoupper(Str::random(10)), 'buyer_id' => $buyer?->id, 'guest_email' => $buyer ? null : 'g@example.com',
            'status' => OrderStatus::Paid, 'subtotal' => $total, 'total' => $total,
            'shipping_address' => ['line1' => 'x'], 'billing_address' => ['line1' => 'x'], 'placed_at' => $at,
        ]);
        $order->forceFill(['created_at' => $at])->save();

        $line = $order->items()->create(array_merge([
            'seller_id' => $seller->id, 'product_id' => $product->id, 'product_title' => $product->title,
            'quantity' => 1, 'unit_price' => $total, 'line_total' => $total, 'commission_rate' => 10,
            'platform_fee_amount' => $total / 10, 'seller_payout_amount' => $total * 0.9, 'status' => $status,
        ], $item));
        $line->forceFill(['created_at' => $at])->save();

        return $line;
    }

    private function staff(User $owner, array $abilities): User
    {
        $u = $this->withTwoFactor(User::factory()->create(['email' => Str::random(6).'@example.com']));
        SellerTeamMember::create(['owner_id' => $owner->id, 'user_id' => $u->id, 'email' => $u->email, 'role' => 'viewer', 'abilities' => $abilities, 'status' => 'active']);

        return $u;
    }

    // ================= analytics =================

    public function test_analytics_counts_only_real_sales_and_compares_to_previous_period(): void
    {
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $p = $this->product($seller);
        $this->line($seller, $buyer, OrderStatus::Delivered, 100, ['product' => $p]);
        $this->line($seller, $buyer, OrderStatus::Placed, 500, ['product' => $p]);     // never paid
        $this->line($seller, $buyer, OrderStatus::Cancelled, 300, ['product' => $p]);  // cancelled
        $this->line($seller, $buyer, OrderStatus::Refunded, 200, ['product' => $p]);   // refunded
        $this->line($seller, $buyer, OrderStatus::Delivered, 50, ['product' => $p], now()->subDays(45)); // previous 30d window

        foreach (['u1', 'u2', 'u2', 'u3', 'u4'] as $viewer) {
            ProductView::create(['product_id' => $p->id, 'viewer_key' => $viewer, 'viewed_at' => now()]);
        }

        $r = app(SellerAnalyticsService::class)->report($seller->id, 30);
        $this->assertEquals(100.0, $r['current']['revenue']);
        $this->assertSame(1, $r['current']['orders']);
        $this->assertSame(5, $r['current']['views']);
        $this->assertSame(4, $r['current']['visitors']);
        $this->assertEquals(25.0, $r['current']['conversion']);  // 1 order / 4 shoppers
        $this->assertEquals(50.0, $r['previous']['revenue']);
        $this->assertEquals(100.0, SellerAnalyticsService::change($r['current']['revenue'], $r['previous']['revenue']));
        $this->assertSame(1, $r['listings']->first()['orders']);
    }

    public function test_repeat_rate_ignores_guest_checkouts(): void
    {
        $seller = $this->seller();
        $repeat = User::factory()->create();
        $this->line($seller, $repeat, OrderStatus::Delivered);
        $this->line($seller, $repeat, OrderStatus::Delivered);
        $this->line($seller, User::factory()->create(), OrderStatus::Delivered);
        $this->line($seller, null, OrderStatus::Delivered); // guests used to be lumped into one "repeat buyer"
        $this->line($seller, null, OrderStatus::Delivered);

        $this->assertEquals(50.0, app(SellerAnalyticsService::class)->report($seller->id, 30)['repeat_rate']);
    }

    public function test_analytics_export_and_permissions(): void
    {
        $seller = $this->seller();
        $this->line($seller, User::factory()->create(), OrderStatus::Delivered);

        $csv = $this->actingAs($seller)->get(route('seller.analytics.export', ['days' => 7]))->assertOk()->streamedContent();
        $this->assertStringContainsString('Date,Revenue,Orders', $csv);
        $this->assertStringContainsString('Ceramic Pads', $csv);

        $packer = $this->staff($seller, ['orders.view']);
        $this->actingAs($packer)->get(route('seller.analytics.index'))->assertForbidden();
        $this->actingAs($packer)->get(route('seller.insights.index'))->assertForbidden();
    }

    // ================= insights =================

    public function test_run_sale_from_insights_uses_a_real_sale_and_respects_permissions(): void
    {
        $seller = $this->seller();
        $p = $this->product($seller);
        $own = $this->product($seller, ['sale_price' => 80]);

        $this->actingAs($seller)->post(route('seller.insights.run-sale', $p), ['pct' => 20])->assertSessionHas('success');
        $this->assertEquals(80.0, $p->fresh()->effectivePrice());
        $this->assertSame(1, SaleEvent::count());

        // Already on its own sale → refused, untouched.
        $this->actingAs($seller)->post(route('seller.insights.run-sale', $own), ['pct' => 20])->assertSessionHas('error');
        $this->assertEquals(80.0, (float) $own->fresh()->sale_price);

        $viewer = $this->staff($seller, ['products.view']);
        $this->actingAs($viewer)->post(route('seller.insights.run-sale', $p))->assertForbidden();
    }

    public function test_insights_spot_traffic_without_sales_and_ignore_shopper_answers(): void
    {
        $seller = $this->seller();
        $looked = $this->product($seller, ['title' => 'Looked-at Rotor Kit']);
        foreach (range(1, 22) as $i) {
            ProductView::create(['product_id' => $looked->id, 'viewer_key' => 'v'.$i, 'viewed_at' => now()->subDays(2)]);
        }

        $q = Question::create(['product_id' => $looked->id, 'author_name' => 'G', 'body' => 'Fits a 2012 Civic?', 'status' => 'published']);
        Answer::create(['question_id' => $q->id, 'author_name' => 'Another shopper', 'is_seller' => false, 'body' => 'Probably']);

        $insights = app(InsightsService::class);
        $this->assertSame('Looked-at Rotor Kit', $insights->trafficNoSales($seller->id)->first()?->title);
        $this->assertSame(1, $insights->unansweredQuestions($seller->id)->count(), 'a shopper answer is not a seller answer');

        $this->line($seller, User::factory()->create(), OrderStatus::Delivered, 100, ['product' => $looked]);
        $this->assertTrue($insights->trafficNoSales($seller->id)->isEmpty());
    }

    // ================= reviews =================

    public function test_review_reply_notifies_once_and_can_be_edited_and_removed(): void
    {
        Notification::fake();
        $seller = $this->seller();
        $buyer = User::factory()->create(['name' => 'Dana Cruz']);
        $p = $this->product($seller);
        $review = Review::create(['product_id' => $p->id, 'user_id' => $buyer->id, 'rating' => 2, 'body' => 'Squeaks.', 'status' => 'published']);
        SellerSavedReply::create(['seller_id' => $seller->id, 'title' => 'Sorry', 'body' => 'Sorry {buyer_first_name} — message us about {product_title}.']);

        $c = Livewire::actingAs($seller)->test(Reviews::class)
            ->call('useReply', $review->id, SellerSavedReply::first()->id);
        $this->assertSame("Sorry Dana — message us about {$p->title}.", $c->get('reply')[$review->id]);
        $c->call('respond', $review->id)->assertHasNoErrors();

        $this->assertNotNull($review->fresh()->seller_response);
        Notification::assertSentToTimes($buyer, SellerRespondedToReview::class, 1);

        $c->call('edit', $review->id)->set("reply.{$review->id}", 'Updated: replacement pads are on the way.')->call('respond', $review->id);
        $this->assertSame('Updated: replacement pads are on the way.', $review->fresh()->seller_response);
        Notification::assertSentToTimes($buyer, SellerRespondedToReview::class, 1); // edits don't re-notify

        $c->call('removeResponse', $review->id);
        $this->assertNull($review->fresh()->seller_response);
    }

    public function test_seller_can_report_a_review_once(): void
    {
        $seller = $this->seller();
        $review = Review::create(['product_id' => $this->product($seller)->id, 'user_id' => User::factory()->create()->id, 'rating' => 1, 'body' => 'UPS was late!!', 'status' => 'published']);

        Livewire::actingAs($seller)->test(Reviews::class)
            ->call('startReport', $review->id)->set('reportReason', 'shipping_carrier')->set('reportNote', 'Carrier delay only')
            ->call('submitReport')->assertHasNoErrors();

        $this->assertNotNull($review->fresh()->reported_at);
        $this->assertSame('published', $review->fresh()->status, 'stays up until an admin decides');
        $this->assertSame(1, AdminAlert::where('type', 'review_reported')->count());

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Services\Seller\ReviewResponseService::class)->report($review->fresh(), 'spam', null);
    }

    public function test_review_reply_permission(): void
    {
        $seller = $this->seller();
        $review = Review::create(['product_id' => $this->product($seller)->id, 'user_id' => User::factory()->create()->id, 'rating' => 5, 'body' => 'Great', 'status' => 'published']);
        $packer = $this->staff($seller, ['orders.view']);

        $this->actingAs($packer)->post(route('seller.reviews.respond', $review), ['response' => 'Thanks a lot!'])->assertForbidden();
        $this->actingAs($this->seller())->post(route('seller.reviews.respond', $review), ['response' => 'Thanks a lot!'])->assertForbidden();
    }

    // ================= scorecard =================

    public function test_scorecard_uses_rolling_window_and_fair_defects(): void
    {
        $seller = $this->seller();
        $buyer = User::factory()->create();

        // Two years ago: a seller-fault cancellation — should no longer count.
        $this->line($seller, $buyer, OrderStatus::Cancelled, 100, ['cancel_reason' => 'out_of_stock'], now()->subYears(2));

        // Recent: 4 delivered, one returned for "No longer needed" (buyer remorse, not a defect).
        $lines = collect(range(1, 4))->map(fn () => $this->line($seller, $buyer, OrderStatus::Delivered, 100, ['shipped_at' => now()->subDays(9)], now()->subDays(10)));
        ReturnRequest::create(['order_item_id' => $lines[0]->id, 'user_id' => $buyer->id, 'reason' => 'No longer needed', 'status' => 'requested']);

        // Buyer-requested cancellation is excused.
        $this->line($seller, $buyer, OrderStatus::Cancelled, 100, ['cancel_reason' => 'buyer_requested']);
        // Never-paid order isn't an order.
        $this->line($seller, $buyer, OrderStatus::Placed);

        $m = app(SellerScorecardService::class)->metrics($seller);
        $this->assertSame(365, $m['window_days']);
        $this->assertEquals(0.0, $m['defect_rate']);
        $this->assertEquals(0.0, $m['cancellation_rate']);

        // A wrong-part return IS a defect.
        ReturnRequest::create(['order_item_id' => $lines[1]->id, 'user_id' => $buyer->id, 'reason' => 'Wrong part received', 'status' => 'requested']);
        // 1 of 4 judged orders (the buyer-requested cancellation isn't judged either way).
        $this->assertEquals(25.0, app(SellerScorecardService::class)->metrics($seller)['defect_rate']);
    }

    public function test_on_time_uses_business_day_handling_deadline(): void
    {
        $seller = $this->seller();
        $p = $this->product($seller, ['handling_days' => 2]);
        $friday = Carbon::parse('next friday 10:00');

        // Paid Friday, 2 business days → due Tuesday. Shipped Tuesday = on time (calendar math said late).
        $this->line($seller, User::factory()->create(), OrderStatus::Shipped, 100, ['product' => $p, 'shipped_at' => $friday->copy()->addDays(4)->setTime(15, 0)], $friday);

        $this->travelTo($friday->copy()->addDays(6));
        $this->assertEquals(100.0, app(SellerScorecardService::class)->metrics($seller)['on_time_ship_rate']);
    }

    public function test_fresh_unanswered_message_does_not_hurt_response_rate(): void
    {
        Notification::fake();
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $c = app(MessagingService::class)->threadFor($buyer, $seller);
        app(MessagingService::class)->send($c, $buyer, 'Does it fit?');

        $this->assertEquals(100.0, app(SellerScorecardService::class)->metrics($seller)['response_rate']);

        $this->travel(30)->hours();
        $this->assertEquals(0.0, app(SellerScorecardService::class)->metrics($seller)['response_rate']);
    }

    // ================= account health =================

    public function test_refund_and_close_claim_does_not_count_against_seller(): void
    {
        Notification::fake();
        $this->mock(RefundService::class)->shouldReceive('refundAmount')->once();
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $line = $this->line($seller, $buyer, OrderStatus::Delivered, 100, ['delivered_at' => now()->subDays(2)]);
        $claim = BuyerClaim::create(['order_item_id' => $line->id, 'buyer_id' => $buyer->id, 'seller_id' => $seller->id, 'reason' => 'not_as_described', 'amount' => 100, 'status' => 'open']);

        $this->assertEquals(100.0, app(AccountHealthService::class)->standing($seller)['claim_rate']);

        Livewire::actingAs($seller)->test(AccountHealth::class)->call('refundClaim', $claim->id);

        $claim->refresh();
        $this->assertSame('granted', $claim->status);
        $this->assertTrue($claim->resolvedBySeller());
        $this->assertSame(OrderStatus::Refunded, $line->fresh()->status);
        Notification::assertSentTo($buyer, ClaimResolvedBySeller::class);
        $this->assertEquals(0.0, app(AccountHealthService::class)->standing($seller)['claim_rate']);
        $this->assertSame(0, BuyerClaim::where('seller_id', $seller->id)->countingAgainstSeller()->count());
    }

    public function test_performance_pages_render(): void
    {
        $seller = $this->seller();
        $this->line($seller, User::factory()->create(), OrderStatus::Delivered);
        foreach (['seller.analytics.index', 'seller.insights.index', 'seller.reviews.index', 'seller.scorecard.index', 'seller.account-health.index'] as $route) {
            $this->actingAs($seller)->get(route($route))->assertOk();
        }
        $this->actingAs($seller)->get(route('seller.analytics.index', ['days' => 365]))->assertOk()->assertSee('Listing performance');
    }
}
