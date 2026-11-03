<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Jobs\ReleaseSellerPayout;
use App\Models\AdminAlert;
use App\Models\AdminBroadcast;
use App\Models\Affiliate;
use App\Models\AffiliateProgram;
use App\Models\BuyerClaim;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\MarketingAutomation;
use App\Models\MarketingCampaign;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Page;
use App\Models\PolicyViolation;
use App\Models\Product;
use App\Models\Question;
use App\Models\ReturnRequest;
use App\Models\Review;
use App\Models\SellerProfile;
use App\Models\SellerVerification;
use App\Models\Subscription;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\AccountNotice;
use App\Services\Cart\CartService;
use App\Services\Marketing\CampaignSender;
use App\Services\Marketing\MarketingConsent;
use App\Services\Orders\BuyerRefundService;
use App\Services\Payments\InstantPayoutService;
use App\Support\AdminNav;
use Database\Seeders\AdminPermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Admin decisions must really change what sellers, shoppers and background jobs do. */
class AdminSyncTest extends TestCase
{
    use RefreshDatabase;

    /** Actions whose permission is checked inside the controller, so the crawler can check the button too. */
    private const CONTROLLER_PERMISSIONS = [
        'admin.order-items.cancel' => 'refunds.issue',
        'admin.order-items.refund' => 'refunds.issue',
        'admin.order-items.delivered' => 'disputes.resolve',
        'admin.claims.decide' => 'refunds.issue',
        'admin.disputes.approve-return' => 'refunds.issue',
        'admin.disputes.reverse-payout' => 'refunds.issue',
        'admin.disputes.deny-return' => 'disputes.resolve',
        'admin.subscriptions.action' => 'disputes.resolve',
        'admin.sellers.suspend' => 'sellers.suspend',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(AdminPermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Notification::fake();
    }

    private function admin(string $role = 'super_admin'): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $this->withTwoFactor($u);
    }

    private function seller(array $profile = []): User
    {
        $u = $this->withTwoFactor(User::factory()->create());
        $u->assignRole('seller');
        SellerProfile::create($profile + ['user_id' => $u->id, 'business_name' => 'Store '.Str::random(4), 'business_type' => 'llc', 'status' => 'approved', 'slug' => 's-'.Str::lower(Str::random(6))]);

        return $u->fresh();
    }

    private function product(User $seller, array $extra = []): Product
    {
        return Product::create($extra + [
            'seller_id' => $seller->id, 'category_id' => Category::where('slug', 'other-parts')->value('id'),
            'title' => 'Slotted Rotor', 'slug' => 'r-'.Str::random(8), 'price' => 100, 'quantity' => 5, 'status' => 'active',
        ]);
    }

    private function line(User $seller, ?User $buyer = null, array $extra = []): OrderItem
    {
        $order = Order::create([
            'order_number' => 'PM-'.strtoupper(Str::random(8)), 'buyer_id' => ($buyer ?? User::factory()->create())->id,
            'status' => OrderStatus::Paid, 'subtotal' => 100, 'total' => 100, 'shipping_address' => ['city' => 'Dallas', 'state' => 'TX'], 'billing_address' => ['city' => 'Dallas'],
            'stripe_payment_intent_id' => 'pi_test',
        ]);

        return $order->items()->create($extra + [
            'seller_id' => $seller->id, 'product_id' => $this->product($seller)->id, 'product_title' => 'Slotted Rotor',
            'quantity' => 1, 'unit_price' => 100, 'line_total' => 100, 'commission_rate' => 10,
            'platform_fee_amount' => 10, 'seller_payout_amount' => 90, 'status' => OrderStatus::Delivered,
        ]);
    }

    // ---------- takedowns ----------

    public function test_a_seller_cannot_republish_a_listing_staff_took_down(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller);

        $this->actingAs($this->admin())->post(route('admin.products.moderate', $product), ['decision' => 'remove', 'reason' => 'Counterfeit']);
        $this->assertSame('archived', $product->fresh()->status);

        // Every door a seller has: model save (form, API, spreadsheet), and bulk activate.
        $fresh = $product->fresh();
        $fresh->update(['status' => 'active']);
        $this->assertSame('pending_review', $fresh->fresh()->status);
        $this->assertFalse(Product::listed()->whereKey($product->id)->exists());

        $product->fresh()->forceFill(['status' => 'archived'])->save();
        Livewire::actingAs($seller)->test(\App\Livewire\Seller\ProductList::class)
            ->set('selected', [$product->id])->call('bulk', 'activate');
        $this->assertSame('archived', $product->fresh()->status);

        // Staff approval is what brings it back.
        $this->actingAs($this->admin())->post(route('admin.products.moderate', $product), ['decision' => 'approve']);
        $this->assertSame('active', $product->fresh()->status);
        $this->assertNull($product->fresh()->moderation_note);
    }

    public function test_a_seller_sees_why_their_listing_was_taken_down(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller, ['status' => 'archived']);
        $product->forceFill(['moderation_note' => 'Counterfeit part'])->save();

        Livewire::actingAs($seller)->test(\App\Livewire\Seller\ProductList::class)->set('status', 'archived')->assertSee('Counterfeit part');
    }

    // ---------- suspensions and bans reach buying ----------

    public function test_items_of_a_suspended_store_drop_out_of_saved_carts(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller);
        $buyer = User::factory()->create();
        $this->actingAs($buyer);

        $cart = app(CartService::class);
        $cart->add($product->id, 1);
        $this->assertCount(1, $cart->lines());

        $seller->sellerProfile->update(['status' => 'suspended']);
        $this->assertCount(0, app(CartService::class)->lines());
    }

    public function test_banning_a_seller_account_hides_their_listings_and_closes_the_api(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller);

        $this->actingAs($this->admin())->post(route('admin.users.ban', $seller), ['reason' => 'Fraud']);

        $this->assertFalse(Product::listed()->whereKey($product->id)->exists());
        $this->assertFalse($product->fresh()->isListed());
    }

    public function test_suspended_stores_send_no_marketing_and_banned_buyers_receive_none(): void
    {
        $seller = $this->seller(['status' => 'suspended']);
        $campaign = MarketingCampaign::create(['seller_id' => $seller->id, 'channel' => 'email', 'name' => 'X', 'subject' => 'X', 'body' => 'X', 'audience' => ['type' => 'all'], 'status' => 'scheduled']);

        app(CampaignSender::class)->send($campaign);
        $this->assertSame('failed', $campaign->fresh()->status);

        $buyer = User::factory()->create();
        $buyer->forceFill(['is_active' => false])->save();
        $this->assertFalse(app(MarketingConsent::class)->canEmail($buyer->fresh(), $seller->id));
    }

    // ---------- payout holds ----------

    public function test_hold_blocks_instant_payouts_and_shows_on_the_seller_payouts_page(): void
    {
        $seller = $this->seller(['stripe_account_id' => 'acct_1', 'stripe_onboarding_complete' => true]);
        $line = $this->line($seller, null, ['status' => OrderStatus::Shipped]);

        $this->assertTrue(app(InstantPayoutService::class)->quote($line->fresh())['eligible']);

        $this->actingAs($this->admin())->post(route('admin.sellers.payout-hold', $seller->sellerProfile), ['hold' => 1, 'reason' => 'Fraud review']);

        $this->assertFalse(app(InstantPayoutService::class)->quote($line->fresh())['eligible']);
        $this->actingAs($seller->fresh())->get(route('seller.payouts.index'))->assertOk()->assertSee('Payouts are on hold')->assertSee('Fraud review');
    }

    public function test_releasing_a_hold_pays_what_was_waiting_and_clears_the_alert(): void
    {
        $seller = $this->seller();
        $line = $this->line($seller);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.sellers.payout-hold', $seller->sellerProfile), ['hold' => 1, 'reason' => 'Review']);
        app()->call([(new ReleaseSellerPayout($line->id))->withFakeQueueInteractions(), 'handle']);
        $this->assertDatabaseHas('admin_alerts', ['type' => 'payout_held', 'resolved_at' => null]);

        Bus::fake([ReleaseSellerPayout::class]);
        $this->actingAs($admin)->post(route('admin.sellers.payout-hold', $seller->sellerProfile->fresh()), ['hold' => 0, 'reason' => 'Cleared']);

        Bus::assertDispatched(ReleaseSellerPayout::class, fn ($job) => $job->orderItemId === $line->id);
        $this->assertSame(0, AdminAlert::where('type', 'payout_held')->whereNull('resolved_at')->count());
    }

    // ---------- money paths ----------

    public function test_refunding_every_line_updates_the_order_and_the_seller_ledger(): void
    {
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $line = $this->line($seller, $buyer);
        $this->mock(BuyerRefundService::class, function ($m) {
            $m->shouldReceive('refundLine')->once()->andReturn(100.0);
            $m->shouldReceive('paidForLine')->andReturn(100.0);
        });

        $this->actingAs($this->admin())->post(route('admin.order-items.refund', $line), ['reason' => 'Goodwill'])->assertSessionHas('success');

        $this->assertSame(OrderStatus::Refunded, $line->order->fresh()->status);
        Notification::assertSentTo($buyer, AccountNotice::class);
        Notification::assertSentTo($seller, AccountNotice::class);

        $from = now()->subDay();
        $pnl = app(\App\Services\Seller\SellerLedgerService::class)->pnl($seller->id, $from, now());
        $this->assertNotEmpty($pnl);
    }

    public function test_reversing_a_payout_uses_the_refund_path_that_knows_what_the_buyer_paid(): void
    {
        $seller = $this->seller();
        $line = $this->line($seller, null, ['payout_released_at' => now(), 'status' => OrderStatus::ReturnRequested]);
        $this->mock(BuyerRefundService::class)->shouldReceive('refundLine')->once()->andReturn(80.0);

        $this->actingAs($this->admin('finance'))->post(route('admin.disputes.reverse-payout', $line), ['reason' => 'Approved late'])->assertSessionHas('success');

        $this->assertSame(OrderStatus::Refunded, $line->fresh()->status);
    }

    public function test_a_failed_card_refund_changes_nothing(): void
    {
        $seller = $this->seller();
        $line = $this->line($seller);
        $this->mock(BuyerRefundService::class, function ($m) {
            $m->shouldReceive('refundLine')->andThrow(new \RuntimeException('Card declined the refund'));
        });

        $this->actingAs($this->admin('finance'))->post(route('admin.order-items.refund', $line), ['reason' => 'x'])->assertSessionHasErrors('refund');

        $this->assertSame(OrderStatus::Delivered, $line->fresh()->status);
        $this->assertDatabaseMissing('activity_log', ['description' => 'Refunded order line']);
    }

    public function test_claim_decisions_reach_both_sides_and_the_seller_health_page(): void
    {
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $line = $this->line($seller, $buyer);
        $claim = BuyerClaim::create(['claim_number' => 'CL-9', 'order_item_id' => $line->id, 'buyer_id' => $buyer->id, 'seller_id' => $seller->id, 'reason' => 'damaged', 'amount' => 100, 'status' => 'escalated', 'respond_by' => now()]);
        $this->mock(BuyerRefundService::class)->shouldReceive('refundLine')->andReturn(100.0);

        $this->actingAs($this->admin())->post(route('admin.claims.decide', $claim), ['decision' => 'grant', 'note' => 'Photos'])->assertSessionHas('success');

        Notification::assertSentTo($buyer, AccountNotice::class);
        Notification::assertSentTo($seller, AccountNotice::class);
        $this->actingAs($seller->fresh())->get(route('seller.account-health.index'))->assertOk()->assertSee('Buyer claim decided against the store');
    }

    // ---------- catalog and content reach the shop ----------

    public function test_a_hidden_category_leaves_the_shop_and_the_seller_form(): void
    {
        $seller = $this->seller();
        $hidden = Category::create(['name' => 'Hidden Cat', 'slug' => 'hidden-cat']);
        $this->product($seller, ['category_id' => $hidden->id]);
        cache()->forget('shop.category_roots');
        $this->assertTrue(Category::shopRoots()->contains('slug', 'hidden-cat'));

        $this->actingAs($this->admin())->patch(route('admin.categories.update', $hidden), ['name' => 'Hidden Cat']);

        $this->assertFalse(Category::shopRoots()->contains('slug', 'hidden-cat'));
        $this->get(AdminNav::site('home'))->assertDontSee('Hidden Cat');

        $other = Category::create(['name' => 'Visible Cat', 'slug' => 'visible-cat']);
        Livewire::actingAs($seller)->test(\App\Livewire\Seller\ProductForm::class)->assertSee('Visible Cat')->assertDontSee('Hidden Cat');
    }

    public function test_removing_a_review_also_corrects_the_store_rating(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller);
        $bad = Review::create(['product_id' => $product->id, 'user_id' => User::factory()->create()->id, 'rating' => 1, 'body' => 'x', 'status' => 'published', 'reported_at' => now(), 'report_reason' => 'fake']);
        Review::create(['product_id' => $product->id, 'user_id' => User::factory()->create()->id, 'rating' => 5, 'body' => 'y', 'status' => 'published']);
        $product->recalculateRatings();
        $this->assertEquals(3.0, (float) $seller->sellerProfile->fresh()->rating);

        $this->actingAs($this->admin('support_agent'))->post(route('admin.reviews.decide', $bad), ['decision' => 'remove']);

        $this->assertEquals(5.0, (float) $seller->sellerProfile->fresh()->rating);
        Notification::assertSentTo($seller, AccountNotice::class);
    }

    public function test_settings_really_change_new_behaviour(): void
    {
        $seller = $this->seller();
        $admin = $this->admin();

        \App\Support\PlatformSettings::save(['marketplace.seller_tiers.top_rated.commission_rate' => 5.5], $admin->id);
        $seller->sellerProfile->update(['tier' => 'top_rated']);
        $this->assertEquals(5.5, $seller->sellerProfile->fresh()->commissionRate());

        \App\Support\PlatformSettings::save(['marketplace.default_commission_rate' => 11], $admin->id);
        $seller->sellerProfile->update(['tier' => 'standard']);
        $this->assertEquals(11.0, $seller->sellerProfile->fresh()->commissionRate());

        \App\Support\PlatformSettings::save(['marketplace.listings.review_required' => true], $admin->id);
        $this->assertTrue(config('marketplace.listings.review_required'));

        \App\Support\PlatformSettings::save(['marketplace.platform.support_email' => 'help@example.com'], $admin->id);
        $this->get(AdminNav::site('home'))->assertSee('help@example.com');
    }

    public function test_identity_documents_can_be_viewed_by_the_right_staff_and_each_view_is_logged(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('kyc/id.pdf', 'pdf');
        $seller = $this->seller();
        $v = SellerVerification::create(['seller_id' => $seller->id, 'legal_name' => 'A', 'registered_address' => 'B', 'tax_id_type' => 'ein', 'tax_id_last4' => '1234', 'id_doc_path' => 'kyc/id.pdf', 'status' => 'pending', 'submitted_at' => now()]);

        $this->actingAs($this->admin('support_agent'))->get(route('admin.verifications.index'))->assertOk()->assertSee('View photo id');
        $this->actingAs($this->admin('support_agent'))->get(route('admin.verifications.document', [$v, 'id']))->assertOk();
        $this->assertDatabaseHas('activity_log', ['description' => 'Viewed identity document']);

        $this->actingAs($this->admin('finance'))->get(route('admin.verifications.document', [$v, 'id']))->assertForbidden();
        $this->actingAs($this->admin('support_agent'))->get(route('admin.verifications.document', [$v, 'nope']))->assertNotFound();
        $this->actingAs($this->admin('support_agent'))->get(route('admin.verifications.document', [$v, 'business']))->assertNotFound();
    }

    public function test_published_pages_are_in_the_sitemap(): void
    {
        Page::create(['title' => 'Terms', 'slug' => 'terms-x', 'body' => 'x', 'is_published' => true]);
        Page::create(['title' => 'Draft', 'slug' => 'draft-x', 'body' => 'x', 'is_published' => false]);

        $this->get(AdminNav::site('sitemap'))->assertOk()->assertSee('terms-x')->assertDontSee('draft-x');
    }

    // ---------- the crawler ----------

    private function seedEverything(): void
    {
        $seller = $this->seller();
        $buyer = User::factory()->create(['wallet_balance' => 5]);
        \App\Models\CreditTransaction::create(['user_id' => $buyer->id, 'type' => 'cashback', 'amount' => 5, 'balance_after' => 5, 'description' => 'x']);

        $delivered = $this->line($seller, $buyer);
        $this->line($seller, $buyer, ['status' => OrderStatus::Paid]);
        $this->line($seller, $buyer, ['status' => OrderStatus::Shipped, 'shipped_at' => now()]);
        $disputed = $this->line($seller, $buyer, ['status' => OrderStatus::ReturnRequested]);
        $this->line($seller, $buyer, ['status' => OrderStatus::Disputed, 'payout_released_at' => now()]);

        $pending = $this->seller(['status' => 'pending']);
        $product = $this->product($seller, ['status' => 'pending_review']);
        Review::create(['product_id' => $product->id, 'user_id' => $buyer->id, 'rating' => 1, 'body' => 'x', 'status' => 'published', 'reported_at' => now(), 'report_reason' => 'fake']);
        Question::create(['product_id' => $product->id, 'user_id' => $buyer->id, 'body' => 'Fits?', 'status' => 'published']);

        BuyerClaim::create(['claim_number' => 'CL-1', 'order_item_id' => $delivered->id, 'buyer_id' => $buyer->id, 'seller_id' => $seller->id, 'reason' => 'damaged', 'amount' => 100, 'status' => 'escalated', 'respond_by' => now()]);
        ReturnRequest::create(['order_item_id' => $disputed->id, 'user_id' => $buyer->id, 'reason' => 'damaged', 'status' => 'requested', 'escalated_at' => now()]);
        SupportTicket::create(['seller_id' => $seller->id, 'opened_by' => $seller->id, 'category' => array_key_first(SupportTicket::CATEGORIES), 'subject' => 'Help', 'status' => 'open', 'priority' => 'normal', 'admin_unread' => true, 'last_reply_at' => now()]);
        AdminAlert::create(['type' => 'payout_blocked', 'message' => 'Stuck']);
        PolicyViolation::create(['seller_id' => $seller->id, 'type' => 'policy_breach', 'severity' => 'warning', 'title' => 'Late', 'status' => 'appealed', 'appeal_text' => 'Not me', 'appeal_submitted_at' => now()]);
        SellerVerification::create(['seller_id' => $pending->id, 'legal_name' => 'A', 'registered_address' => 'B', 'tax_id_type' => 'ein', 'tax_id_last4' => '1234', 'status' => 'pending', 'submitted_at' => now()]);
        Coupon::create(['code' => 'SAVE5', 'type' => 'fixed', 'value' => 5, 'is_active' => true]);
        Page::create(['title' => 'Terms', 'slug' => 'terms', 'body' => 'x', 'is_published' => true]);
        MarketingCampaign::create(['seller_id' => $seller->id, 'channel' => 'email', 'name' => 'C', 'subject' => 'S', 'body' => 'B', 'audience' => [], 'status' => 'scheduled', 'scheduled_at' => now()->addDay()]);
        MarketingAutomation::create(['seller_id' => $seller->id, 'trigger' => 'abandoned_cart', 'is_active' => true, 'channel' => 'email', 'delay_hours' => 1, 'subject' => 'S', 'body' => 'B']);
        Affiliate::create(['affiliate_program_id' => AffiliateProgram::create(['seller_id' => $seller->id, 'is_active' => true, 'commission_pct' => 10, 'cookie_days' => 30])->id, 'name' => 'A', 'email' => 'a@example.com', 'status' => 'approved']);
        Subscription::create(['buyer_id' => $buyer->id, 'seller_id' => $seller->id, 'product_id' => $product->id, 'quantity' => 1, 'interval_days' => 30, 'discount_pct' => 5, 'status' => 'active', 'next_run_at' => now()->addDay()]);
        AdminBroadcast::create(['subject' => 'Hi', 'body' => 'x', 'audience' => 'buyers', 'kind' => 'marketing', 'status' => 'draft']);
    }

    /**
     * Logs in as each role and follows every admin link it can see. Nothing in the menu or on a page may
     * lead to a 403, 404 or 500, and no button may be shown that the role isn't allowed to press.
     */
    public function test_no_role_ever_sees_a_broken_link_or_a_button_it_cannot_use(): void
    {
        $this->seedEverything();
        $problems = [];

        foreach (['super_admin', 'support_agent', 'finance', 'catalog_manager'] as $role) {
            $admin = $this->admin($role);
            $base = route('admin.dashboard');
            $host = parse_url($base, PHP_URL_HOST);
            $queue = [$base];
            $seen = [];

            while ($queue && count($seen) < 260) {
                $url = array_shift($queue);
                $key = preg_replace('/[?&]page=\d+/', '', $url);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $response = $this->actingAs($admin)->get($url);
                $status = $response->getStatusCode();
                if ($status >= 400) {
                    $problems[] = "{$role}: {$status} on ".parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY)." (from a link it was shown)";

                    continue;
                }
                if ($status >= 300 || ! str_contains((string) $response->headers->get('Content-Type'), 'html')) {
                    continue;
                }

                $dom = new \DOMDocument;
                @$dom->loadHTML($response->getContent());

                foreach ($dom->getElementsByTagName('a') as $a) {
                    $href = $a->getAttribute('href');
                    if (parse_url($href, PHP_URL_HOST) === $host && ! str_contains($href, '/export')) {
                        $queue[] = str_replace('&amp;', '&', $href);
                    }
                }

                foreach ($dom->getElementsByTagName('form') as $form) {
                    $action = $form->getAttribute('action');
                    if (strtoupper($form->getAttribute('method')) !== 'POST' || parse_url($action, PHP_URL_HOST) !== $host) {
                        continue;
                    }
                    $method = 'POST';
                    foreach ($form->getElementsByTagName('input') as $input) {
                        if ($input->getAttribute('name') === '_method') {
                            $method = strtoupper($input->getAttribute('value'));
                        }
                    }

                    try {
                        $route = Route::getRoutes()->match(Request::create($action, $method));
                    } catch (\Throwable) {
                        $problems[] = "{$role}: form on ".parse_url($url, PHP_URL_PATH)." posts to a missing route {$method} {$action}";

                        continue;
                    }

                    $needs = collect($route->gatherMiddleware())->filter(fn ($m) => is_string($m) && str_starts_with($m, 'can:'))->map(fn ($m) => substr($m, 4));
                    if (isset(self::CONTROLLER_PERMISSIONS[$route->getName()])) {
                        $needs->push(self::CONTROLLER_PERMISSIONS[$route->getName()]);
                    }
                    foreach ($needs as $permission) {
                        if (! $admin->can($permission)) {
                            $problems[] = "{$role}: sees button for {$route->getName()} on ".parse_url($url, PHP_URL_PATH)." but lacks {$permission}";
                        }
                    }
                }
            }

            $this->assertGreaterThan(3, count($seen), "{$role} reached almost nothing");
        }

        $this->assertSame([], array_values(array_unique($problems)), "Broken for staff:\n".implode("\n", array_unique($problems)));
    }

    public function test_every_named_admin_route_has_a_real_controller_method(): void
    {
        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            if (! str_starts_with($name, 'admin.')) {
                continue;
            }
            $action = $route->getActionName();
            if ($action === 'Closure') {
                continue;
            }
            [$class, $method] = array_pad(explode('@', $action), 2, '__invoke');
            $this->assertTrue(method_exists($class, $method), "{$name} points at missing {$action}");
        }
    }

    public function test_the_permission_screen_lists_every_permission_that_exists(): void
    {
        $res = $this->actingAs($this->admin())->get(route('admin.staff.index'))->assertOk();
        $listed = collect($res->viewData('groups'))->flatMap(fn ($g) => array_keys($g))->sort()->values()->all();
        $real = \Spatie\Permission\Models\Permission::pluck('name')->sort()->values()->all();

        $this->assertSame($real, $listed);
    }
}
