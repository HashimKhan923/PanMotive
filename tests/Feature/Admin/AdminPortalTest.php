<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Jobs\ReleaseSellerPayout;
use App\Models\BuyerClaim;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Page;
use App\Models\Product;
use App\Models\Review;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Orders\BuyerRefundService;
use App\Support\AdminNav;
use App\Support\PlatformSettings;
use Database\Seeders\AdminPermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminPortalTest extends TestCase
{
    use RefreshDatabase;

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
        SellerProfile::create($profile + ['user_id' => $u->id, 'business_name' => 'Rotor Room', 'business_type' => 'llc', 'status' => 'approved', 'slug' => 'rr-'.Str::random(6)]);

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
            'status' => OrderStatus::Paid, 'subtotal' => 100, 'total' => 100, 'shipping_address' => ['city' => 'Dallas'], 'billing_address' => ['city' => 'Dallas'],
            'stripe_payment_intent_id' => 'pi_test',
        ]);

        return $order->items()->create($extra + [
            'seller_id' => $seller->id, 'product_id' => $this->product($seller)->id, 'product_title' => 'Slotted Rotor',
            'quantity' => 1, 'unit_price' => 100, 'line_total' => 100, 'commission_rate' => 10,
            'platform_fee_amount' => 10, 'seller_payout_amount' => 90, 'status' => OrderStatus::Delivered,
        ]);
    }

    public function test_every_console_page_opens_for_a_super_admin(): void
    {
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $line = $this->line($seller, $buyer);
        Page::create(['title' => 'Terms', 'slug' => 'terms', 'body' => '<p>x</p>', 'is_published' => true]);
        $admin = $this->admin();

        $pages = [
            route('admin.dashboard'), route('admin.dashboard', ['days' => 365]), route('admin.alerts.index'),
            route('admin.users.index'), route('admin.users.index', ['type' => 'buyers', 'q' => 'a']), route('admin.users.show', $buyer),
            route('admin.sellers.index'), route('admin.sellers.show', $seller->sellerProfile), route('admin.verifications.index'), route('admin.appeals.index'),
            route('admin.staff.index'), route('admin.products.index'), route('admin.products.show', $line->product_id),
            route('admin.categories.index'), route('admin.brands.index'), route('admin.vehicles.index'),
            route('admin.reviews.index'), route('admin.questions.index'),
            route('admin.orders.index'), route('admin.orders.show', $line->order_id), route('admin.returns.index'), route('admin.disputes.index'),
            route('admin.finance.overview'), route('admin.finance.ledger'), route('admin.finance.payouts'), route('admin.finance.wallets'), route('admin.finance.tax'),
            route('admin.coupons.index'), route('admin.ads.index'), route('admin.offsite-ads.index'),
            route('admin.content.index'), route('admin.pages.index'), route('admin.pages.create'), route('admin.pages.edit', 'terms'),
            route('admin.support.index'), route('admin.settings.index'), route('admin.audit.index'), route('admin.system.index'),
        ];

        foreach ($pages as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }

    public function test_each_role_only_gets_its_own_sections(): void
    {
        $support = $this->admin('support_agent');
        $this->actingAs($support)->get(route('admin.orders.index'))->assertOk();
        $this->actingAs($support)->get(route('admin.settings.index'))->assertForbidden();
        $this->actingAs($support)->get(route('admin.finance.overview'))->assertForbidden();
        $this->actingAs($support)->get(route('admin.staff.index'))->assertForbidden();
        $this->actingAs($support)->get(route('admin.dashboard'))->assertOk()->assertDontSee('Gross sales');

        $catalog = $this->admin('catalog_manager');
        $this->actingAs($catalog)->get(route('admin.products.index'))->assertOk();
        $this->actingAs($catalog)->get(route('admin.users.index'))->assertForbidden();

        $finance = $this->admin('finance');
        $this->actingAs($finance)->get(route('admin.finance.overview'))->assertOk();
        $this->actingAs($finance)->get(route('admin.products.index'))->assertForbidden();
    }

    public function test_a_suspended_account_is_signed_out_and_cannot_log_in(): void
    {
        $buyer = User::factory()->create(['password' => 'secret-password']);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.users.ban', $buyer), [])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post(route('admin.users.ban', $buyer), ['reason' => 'Fraud'])->assertRedirect();
        $this->assertFalse($buyer->fresh()->is_active);

        $this->actingAs($buyer->fresh())->get(route('home'))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->assertDatabaseHas('activity_log', ['description' => 'Suspended account', 'subject_id' => $buyer->id]);
    }

    public function test_only_staff_managers_can_touch_staff_accounts(): void
    {
        $this->seed(RoleSeeder::class);
        $target = $this->admin('finance');
        $manager = $this->admin('support_agent');
        $manager->givePermissionTo('users.manage');

        $this->actingAs($manager)->post(route('admin.users.ban', $target), ['reason' => 'x'])->assertForbidden();
    }

    public function test_wallet_adjustments_stay_in_the_ledger_and_cannot_go_negative(): void
    {
        $buyer = User::factory()->create(['wallet_balance' => 10]);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.users.wallet', $buyer), ['direction' => 'remove', 'amount' => 50, 'reason' => 'Oops'])->assertSessionHasErrors('amount');
        $this->assertEquals(10, (float) $buyer->fresh()->wallet_balance);

        $this->actingAs($admin)->post(route('admin.users.wallet', $buyer), ['direction' => 'add', 'amount' => 5, 'reason' => 'Goodwill'])->assertRedirect();
        $this->assertEquals(15, (float) $buyer->fresh()->wallet_balance);
        $this->assertDatabaseHas('credit_transactions', ['user_id' => $buyer->id, 'type' => 'admin_adjustment', 'balance_after' => 15]);

        $this->actingAs($this->admin('support_agent'))->post(route('admin.users.wallet', $buyer), ['direction' => 'add', 'amount' => 5, 'reason' => 'x'])->assertForbidden();
    }

    public function test_viewing_as_a_user_is_logged_blocks_spending_and_returns_to_admin(): void
    {
        $buyer = User::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.users.impersonate', $buyer), [])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post(route('admin.users.impersonate', $buyer), ['reason' => 'Ticket 42'])->assertRedirect(AdminNav::site('home'));
        $this->assertAuthenticatedAs($buyer);
        $this->assertDatabaseHas('activity_log', ['description' => 'Started viewing as user', 'subject_id' => $buyer->id]);

        $this->get(AdminNav::site('home'))->assertSee('You are viewing as');
        $this->post(AdminNav::site('checkout.store'), [])->assertSessionHas('error');

        $this->post(route('impersonate.stop'))->assertRedirect(route('admin.users.show', $buyer));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_staff_cannot_be_viewed_as_and_support_cannot_impersonate(): void
    {
        $finance = $this->admin('finance');
        $this->actingAs($this->admin())->post(route('admin.users.impersonate', $finance), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->admin('support_agent'))->post(route('admin.users.impersonate', User::factory()->create()), ['reason' => 'x'])->assertForbidden();
    }

    public function test_seller_commission_override_applies_and_is_logged(): void
    {
        $seller = $this->seller();
        $profile = $seller->sellerProfile;

        $this->actingAs($this->admin())->post(route('admin.sellers.commission', $profile), ['rate' => 7.5, 'reason' => 'Key account'])->assertRedirect();
        $this->assertEquals(7.5, $profile->fresh()->commissionRate());
        $this->assertDatabaseHas('activity_log', ['description' => 'Changed seller commission']);

        $this->actingAs($this->admin('support_agent'))->post(route('admin.sellers.commission', $profile), ['rate' => 1, 'reason' => 'x'])->assertForbidden();
    }

    public function test_payout_hold_stops_the_payout_job(): void
    {
        $seller = $this->seller();
        $line = $this->line($seller);

        $this->actingAs($this->admin())->post(route('admin.sellers.payout-hold', $seller->sellerProfile), ['hold' => 1, 'reason' => 'Fraud review'])->assertRedirect();
        $this->assertTrue((bool) $seller->sellerProfile->fresh()->payouts_held);

        $job = (new ReleaseSellerPayout($line->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $this->assertNull($line->fresh()->payout_released_at);
        $this->assertDatabaseHas('admin_alerts', ['type' => 'payout_held', 'related_order_item_id' => $line->id]);
    }

    public function test_locked_tier_survives_automatic_scoring(): void
    {
        $seller = $this->seller();
        $this->actingAs($this->admin())->post(route('admin.sellers.tier', $seller->sellerProfile), ['tier' => 'powerseller', 'locked' => 1, 'reason' => 'Partner'])->assertRedirect();

        app(\App\Services\Seller\SellerScorecardService::class)->refreshTier($seller->fresh());

        $this->assertSame('powerseller', $seller->sellerProfile->fresh()->tier);
    }

    public function test_suspending_hides_listings_and_reinstating_brings_them_back(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.sellers.suspend', $seller->sellerProfile), ['reason' => 'Fraud'])->assertRedirect();
        $this->assertFalse(Product::listed()->whereKey($product->id)->exists());

        $this->actingAs($admin)->post(route('admin.sellers.reinstate', $seller->sellerProfile->fresh()))->assertRedirect();
        $this->assertTrue(Product::listed()->whereKey($product->id)->exists());
    }

    public function test_listing_moderation_and_bulk_actions(): void
    {
        $seller = $this->seller();
        $pending = $this->product($seller, ['status' => 'pending_review']);
        $live = $this->product($seller);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.products.moderate', $pending), ['decision' => 'approve'])->assertRedirect();
        $this->assertSame('active', $pending->fresh()->status);

        $this->actingAs($admin)->post(route('admin.products.moderate', $live), ['decision' => 'remove'])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post(route('admin.products.bulk'), ['decision' => 'remove', 'reason' => 'Counterfeit', 'ids' => [$live->id, $pending->id]])->assertRedirect();
        $this->assertSame('archived', $live->fresh()->status);
        $this->assertSame('Counterfeit', $live->fresh()->moderation_note);
        $this->assertFalse(Product::listed()->whereKey($live->id)->exists());
        Notification::assertSentTo($seller, \App\Notifications\AccountNotice::class);
    }

    public function test_deleting_a_category_moves_its_listings_or_refuses(): void
    {
        $seller = $this->seller();
        $from = Category::create(['name' => 'Old', 'slug' => 'old']);
        $to = Category::create(['name' => 'New', 'slug' => 'new']);
        $product = $this->product($seller, ['category_id' => $from->id]);
        $admin = $this->admin();

        $this->actingAs($admin)->delete(route('admin.categories.destroy', $from))->assertSessionHas('error');
        $this->assertDatabaseHas('categories', ['id' => $from->id]);

        $this->actingAs($admin)->delete(route('admin.categories.destroy', $from), ['move_to' => $to->id])->assertSessionHas('success');
        $this->assertDatabaseMissing('categories', ['id' => $from->id]);
        $this->assertSame($to->id, $product->fresh()->category_id);
    }

    public function test_merging_brands_moves_listings(): void
    {
        $seller = $this->seller();
        $dupe = \App\Models\Brand::create(['name' => 'AC Delco', 'slug' => 'ac-delco']);
        $real = \App\Models\Brand::create(['name' => 'ACDelco', 'slug' => 'acdelco']);
        $product = $this->product($seller, ['brand_id' => $dupe->id]);

        $this->actingAs($this->admin())->post(route('admin.brands.merge', $dupe), ['into' => $real->id])->assertSessionHas('success');

        $this->assertSame($real->id, $product->fresh()->brand_id);
        $this->assertDatabaseMissing('brands', ['id' => $dupe->id]);
    }

    public function test_vehicle_years_are_added_once_and_fitted_ones_cannot_be_deleted(): void
    {
        $seller = $this->seller();
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.vehicles.makes.store'), ['name' => 'Lexus'])->assertRedirect();
        $make = \App\Models\VehicleMake::where('slug', 'lexus')->first();
        $this->actingAs($admin)->post(route('admin.vehicles.models.store'), ['vehicle_make_id' => $make->id, 'name' => 'LC 500'])->assertRedirect();
        $model = \App\Models\VehicleModel::where('slug', 'lc-500')->first();

        $this->actingAs($admin)->post(route('admin.vehicles.years.store'), ['vehicle_model_id' => $model->id, 'from' => 2018, 'to' => 2020]);
        $this->actingAs($admin)->post(route('admin.vehicles.years.store'), ['vehicle_model_id' => $model->id, 'from' => 2019, 'to' => 2021]);
        $this->assertSame(4, \App\Models\Vehicle::where('vehicle_model_id', $model->id)->count());

        $vehicle = \App\Models\Vehicle::where('vehicle_model_id', $model->id)->first();
        $this->product($seller)->fitments()->attach($vehicle->id);
        $this->actingAs($admin)->delete(route('admin.vehicles.destroy', $vehicle))->assertSessionHas('error');
        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id]);
    }

    public function test_removing_a_reported_review_updates_the_rating(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller);
        $review = Review::create(['product_id' => $product->id, 'user_id' => User::factory()->create()->id, 'rating' => 1, 'body' => 'Bad', 'status' => 'published', 'reported_at' => now(), 'report_reason' => 'fake']);
        $product->recalculateRatings();
        $this->assertEquals(1, (int) $product->fresh()->reviews_count);

        $this->actingAs($this->admin('support_agent'))->get(route('admin.reviews.index'))->assertOk()->assertSee('Bad');
        $this->actingAs($this->admin('support_agent'))->post(route('admin.reviews.decide', $review), ['decision' => 'remove'])->assertRedirect();

        $this->assertSame('rejected', $review->fresh()->status);
        $this->assertEquals(0, (int) $product->fresh()->reviews_count);
    }

    public function test_granting_a_claim_refunds_through_the_real_path_not_free_wallet_money(): void
    {
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $line = $this->line($seller, $buyer);
        $claim = BuyerClaim::create(['claim_number' => 'CL-1', 'order_item_id' => $line->id, 'buyer_id' => $buyer->id, 'seller_id' => $seller->id, 'reason' => 'damaged', 'amount' => 100, 'status' => 'escalated', 'respond_by' => now()]);

        $this->mock(BuyerRefundService::class)->shouldReceive('refundLine')->once()->andReturn(100.0);

        $this->actingAs($this->admin())->post(route('admin.claims.decide', $claim), ['decision' => 'grant', 'note' => 'Photos prove it'])->assertSessionHas('success');

        $this->assertSame('granted', $claim->fresh()->status);
        $this->assertSame(OrderStatus::Refunded, $line->fresh()->status);
        $this->assertEquals(0, (float) $buyer->fresh()->wallet_balance);
        $this->assertDatabaseHas('policy_violations', ['seller_id' => $seller->id, 'type' => 'claim_granted']);

        // Deciding it twice must not refund twice.
        $this->actingAs($this->admin())->post(route('admin.claims.decide', $claim), ['decision' => 'grant'])->assertSessionHasErrors('claim');
    }

    public function test_refunding_a_line_needs_permission_and_a_reason(): void
    {
        $seller = $this->seller();
        $line = $this->line($seller);
        $this->mock(BuyerRefundService::class)->shouldReceive('refundLine')->once()->andReturn(100.0);

        $this->actingAs($this->admin('support_agent'))->post(route('admin.order-items.refund', $line), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->admin('finance'))->post(route('admin.order-items.refund', $line), [])->assertSessionHasErrors('reason');
        $this->actingAs($this->admin('finance'))->post(route('admin.order-items.refund', $line), ['reason' => 'Goodwill'])->assertSessionHas('success');
        $this->assertSame(OrderStatus::Refunded, $line->fresh()->status);

        $this->actingAs($this->admin('finance'))->post(route('admin.order-items.refund', $line->fresh()), ['reason' => 'Again'])->assertSessionHasErrors('refund');
    }

    public function test_mark_delivered_starts_the_payout(): void
    {
        Bus::fake([ReleaseSellerPayout::class]);
        $seller = $this->seller();
        $line = $this->line($seller, null, ['status' => OrderStatus::Shipped]);

        $this->actingAs($this->admin('support_agent'))->post(route('admin.order-items.delivered', $line), ['reason' => 'Carrier never scanned'])->assertSessionHas('success');

        $this->assertSame(OrderStatus::Delivered, $line->fresh()->status);
        Bus::assertDispatched(ReleaseSellerPayout::class);
    }

    public function test_finance_totals_are_correct(): void
    {
        $seller = $this->seller();
        $this->line($seller);
        $this->line($seller, null, ['status' => OrderStatus::Refunded]);
        User::factory()->create(['wallet_balance' => 12.5]);

        $res = $this->actingAs($this->admin('finance'))->get(route('admin.finance.overview'));
        $res->assertOk();
        $this->assertEquals(10.0, $res->viewData('revenue')['Commission']);
        $this->assertEquals(12.5, $res->viewData('walletOwed'));
        $this->assertEquals(90.0, $res->viewData('owedSellers'));
    }

    public function test_csv_exports_neutralise_spreadsheet_formulas(): void
    {
        $seller = $this->seller(['business_name' => '=HYPERLINK("http://evil")']);
        $this->line($seller);

        $csv = $this->actingAs($this->admin('finance'))->get(route('admin.finance.export'))->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_coupon_rules_and_toggle(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.coupons.store'), ['code' => 'bad code!', 'type' => 'percentage', 'value' => 10])->assertSessionHasErrors('code');
        $this->actingAs($admin)->post(route('admin.coupons.store'), ['code' => 'save10', 'type' => 'percentage', 'value' => 150])->assertSessionHasErrors('value');
        $this->actingAs($admin)->post(route('admin.coupons.store'), ['code' => 'save10', 'type' => 'percentage', 'value' => 10])->assertSessionHas('success');

        $coupon = Coupon::where('code', 'SAVE10')->firstOrFail();
        $this->assertNull($coupon->seller_id);

        $this->actingAs($admin)->post(route('admin.coupons.toggle', $coupon));
        $this->assertFalse((bool) $coupon->fresh()->is_active);
    }

    public function test_settings_apply_at_once_and_are_audited_and_validated(): void
    {
        $admin = $this->admin();
        $field = 'settings.'.\App\Http\Controllers\Admin\SettingsController::inputName('marketplace.default_commission_rate');

        $this->actingAs($admin)->post(route('admin.settings.update'), ['group' => 'commerce', 'settings' => [
            'marketplace__default_commission_rate' => 99, 'marketplace__instant_payout_fee_pct' => 1.5, 'marketplace__auto_confirm_delivery_days' => 7,
            'marketplace__returns__window_days' => 30, 'marketplace__unpaid_order_hours' => 48, 'marketplace__abandoned_cart_hours' => 4,
        ]])->assertSessionHasErrors($field);

        $this->actingAs($admin)->post(route('admin.settings.update'), ['group' => 'commerce', 'settings' => [
            'marketplace__default_commission_rate' => 12, 'marketplace__instant_payout_fee_pct' => config('marketplace.instant_payout_fee_pct'), 'marketplace__auto_confirm_delivery_days' => config('marketplace.auto_confirm_delivery_days'),
            'marketplace__returns__window_days' => config('marketplace.returns.window_days'), 'marketplace__unpaid_order_hours' => config('marketplace.unpaid_order_hours'), 'marketplace__abandoned_cart_hours' => config('marketplace.abandoned_cart_hours'),
        ]])->assertSessionHas('success');

        $this->assertEquals(12.0, config('marketplace.default_commission_rate'));
        $this->assertDatabaseHas('platform_settings', ['key' => 'marketplace.default_commission_rate']);
        $this->assertDatabaseHas('activity_log', ['description' => 'Changed setting: Default commission']);

        // A fresh boot reads it back from the database.
        config(['marketplace.default_commission_rate' => 10]);
        cache()->flush();
        PlatformSettings::apply();
        $this->assertEquals(12.0, config('marketplace.default_commission_rate'));
    }

    public function test_every_registered_setting_points_at_real_config(): void
    {
        foreach (PlatformSettings::fields() as $key => $field) {
            $this->assertTrue(\Illuminate\Support\Arr::has(config()->all(), $key), "{$key} is not in config");
        }
    }

    public function test_site_switches_really_work(): void
    {
        $admin = $this->admin();
        $buyer = User::factory()->create();

        config(['marketplace.platform.maintenance' => true]);
        $this->get(AdminNav::site('home'))->assertStatus(503)->assertSee('right back');
        $this->actingAs($buyer)->get(AdminNav::site('home'))->assertStatus(503);
        $this->actingAs($admin)->get(AdminNav::site('home'))->assertOk();
        $this->actingAs($admin)->get(route('admin.settings.index'))->assertOk();
        config(['marketplace.platform.maintenance' => false]);

        config(['marketplace.platform.checkout_enabled' => false]);
        $this->actingAs($buyer)->get(AdminNav::site('checkout.index'))->assertRedirect(AdminNav::site('cart.index'));
        config(['marketplace.platform.checkout_enabled' => true]);

        config(['marketplace.platform.seller_signups' => false]);
        $this->actingAs($buyer)->get(route('seller.onboarding.start'))->assertRedirectContains('/');
        $this->assertStringNotContainsString('onboarding', $this->actingAs($buyer)->get(route('seller.onboarding.start'))->headers->get('Location'));
    }

    public function test_announcement_shows_on_the_shop_and_stops_when_it_expires(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.content.update'), ['announcement' => 'Free shipping weekend', 'announcement_tone' => 'accent'])->assertSessionHas('success');
        $this->get(AdminNav::site('home'))->assertSee('Free shipping weekend');

        $this->actingAs($admin)->post(route('admin.content.update'), ['announcement' => 'Free shipping weekend', 'announcement_tone' => 'accent', 'announcement_until' => now()->subHour()->toDateTimeString()]);
        $this->get(AdminNav::site('home'))->assertDontSee('Free shipping weekend');

        $this->actingAs($admin)->post(route('admin.content.update'), ['announcement' => 'x', 'announcement_tone' => 'accent', 'announcement_link' => 'javascript:alert(1)'])->assertSessionHasErrors('announcement_link');
    }

    public function test_pages_publish_sanitise_and_appear_in_the_footer(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.pages.store'), [
            'title' => 'Return Policy', 'body' => '<p>Thirty days</p><script>alert(1)</script>', 'is_published' => 1, 'show_in_footer' => 1,
        ])->assertRedirect();

        $page = Page::where('slug', 'return-policy')->firstOrFail();
        $this->assertStringNotContainsString('<script', $page->body);

        cache()->forget('footer.pages');
        $this->get(AdminNav::site('pages.show', $page))->assertOk()->assertSee('Thirty days');
        $this->get(AdminNav::site('home'))->assertSee('Return Policy');

        $page->update(['is_published' => false]);
        $this->get(AdminNav::site('pages.show', $page))->assertNotFound();
    }

    public function test_staff_role_changes_keep_one_super_admin(): void
    {
        $only = $this->admin();

        $this->actingAs($only)->post(route('admin.staff.role', $only), ['role' => 'finance'])->assertSessionHas('error');
        $this->assertTrue($only->fresh()->hasRole('super_admin'));

        $second = $this->admin();
        $this->actingAs($only)->delete(route('admin.staff.remove', $only))->assertSessionHas('error');
        $this->actingAs($only)->delete(route('admin.staff.remove', $second))->assertSessionHas('success');
        $this->assertFalse($second->fresh()->hasRole('super_admin'));
    }

    public function test_permission_matrix_changes_a_role_but_never_super_admin(): void
    {
        $admin = $this->admin();
        $support = $this->admin('support_agent');

        $this->actingAs($support)->get(route('admin.products.index'))->assertForbidden();

        $this->actingAs($admin)->post(route('admin.staff.matrix'), ['permissions' => [
            'support_agent' => ['orders.view', 'catalog.manage'],
            'super_admin' => [],
        ]])->assertSessionHas('success');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($support->fresh())->get(route('admin.products.index'))->assertOk();
        $this->assertTrue($admin->fresh()->can('settings.manage'));
    }

    public function test_search_finds_records_and_respects_permissions(): void
    {
        $buyer = User::factory()->create(['name' => 'Findable Person']);

        $this->actingAs($this->admin())->getJson(route('admin.search', ['q' => 'Findable']))
            ->assertOk()->assertJsonFragment(['label' => 'Findable Person']);

        $this->actingAs($this->admin('catalog_manager'))->getJson(route('admin.search', ['q' => 'Findable']))
            ->assertOk()->assertJsonMissing(['label' => 'Findable Person']);
    }

    public function test_audit_log_is_filterable_and_system_page_never_shows_secret_values(): void
    {
        config(['services.stripe.secret' => 'sk_live_super_secret_value']);
        $admin = $this->admin();
        activity('admin')->causedBy($admin)->log('Something notable');
        activity('admin')->causedBy($admin)->log('Other thing');

        $this->actingAs($admin)->get(route('admin.audit.index', ['q' => 'notable']))->assertOk()->assertSee('Something notable')->assertDontSee('Other thing');
        $this->actingAs($admin)->get(route('admin.system.index'))->assertOk()->assertDontSee('sk_live_super_secret_value');
    }

    public function test_admin_create_command_makes_a_working_super_admin(): void
    {
        $this->artisan('admin:create', ['email' => 'boss@example.com'])->assertSuccessful();

        $boss = User::where('email', 'boss@example.com')->firstOrFail();
        $this->assertTrue($boss->hasRole('super_admin'));
        $this->assertTrue($boss->can('system.manage'));
    }

    public function test_new_oversight_pages_open(): void
    {
        $admin = $this->admin();
        foreach ([route('admin.broadcasts.index'), route('admin.subscriptions.index'), route('admin.affiliates.index'), route('admin.campaigns.index')] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
        $this->actingAs($this->admin('support_agent'))->get(route('admin.broadcasts.index'))->assertForbidden();
    }

    public function test_broadcast_needs_the_right_count_and_only_sends_once(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $admin = $this->admin();
        $buyer = User::factory()->create();
        $optedOut = User::factory()->create();
        \App\Models\MarketingUnsubscribe::create(['user_id' => $optedOut->id, 'seller_id' => null, 'source' => 'link']);
        $banned = User::factory()->create();
        $banned->forceFill(['is_active' => false])->save();
        $this->seller();

        $this->actingAs($admin)->post(route('admin.broadcasts.store'), [
            'subject' => 'Big sale', 'body' => '<p>Hello</p><script>alert(1)</script>', 'audience' => 'buyers', 'kind' => 'marketing',
        ])->assertSessionHas('success');
        $b = \App\Models\AdminBroadcast::firstOrFail();
        $this->assertStringNotContainsString('<script', $b->body);

        $this->actingAs($admin)->post(route('admin.broadcasts.test', $b))->assertSessionHas('success');
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\CampaignMail::class, fn ($m) => $m->hasTo($admin->email));

        $this->actingAs($admin)->post(route('admin.broadcasts.send', $b), ['confirm' => '999'])->assertSessionHasErrors('confirm');
        $this->assertSame('draft', $b->fresh()->status);

        // sync queue in tests: this runs the job
        $this->actingAs($admin)->post(route('admin.broadcasts.send', $b), ['confirm' => '1'])->assertSessionHas('success');
        $b->refresh();
        $this->assertSame('sent', $b->status);
        $this->assertSame(1, $b->sent_count);
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\CampaignMail::class, fn ($m) => $m->hasTo($buyer->email) && $m->unsubscribeUrl);
        \Illuminate\Support\Facades\Mail::assertNotSent(\App\Mail\CampaignMail::class, fn ($m) => $m->hasTo($optedOut->email) || $m->hasTo($banned->email));

        $this->actingAs($admin)->post(route('admin.broadcasts.send', $b), ['confirm' => '1'])->assertSessionHas('error');
        $this->assertDatabaseHas('activity_log', ['description' => 'Sent broadcast']);
    }

    public function test_homepage_text_is_editable_and_escaped(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.content.home'), [
            'hero_badge' => 'Now open', 'hero_line_1' => 'Parts <b>you</b> need.', 'hero_line_2' => 'Second line.', 'hero_accent' => 'Today.', 'hero_text' => 'Great parts.',
        ])->assertSessionHas('success');

        $html = $this->get(AdminNav::site('home'))->assertOk()->assertSee('Now open')->assertSee('Today.')->getContent();
        $this->assertStringNotContainsString('<b>you</b>', $html);

        $this->actingAs($admin)->post(route('admin.content.home'), ['hero_line_1' => '', 'hero_line_2' => 'x', 'hero_text' => 'x'])->assertSessionHasErrors('hero_line_1');
    }

    public function test_subscription_oversight_needs_permission_and_a_reason(): void
    {
        $seller = $this->seller();
        $sub = \App\Models\Subscription::create([
            'buyer_id' => User::factory()->create()->id, 'seller_id' => $seller->id, 'product_id' => $this->product($seller)->id,
            'quantity' => 1, 'interval_days' => 30, 'discount_pct' => 5, 'status' => 'active', 'next_run_at' => now()->addDays(3),
        ]);

        $this->actingAs($this->admin('finance'))->post(route('admin.subscriptions.action', $sub), ['action' => 'cancel', 'reason' => 'x'])->assertForbidden();
        $this->actingAs($this->admin())->post(route('admin.subscriptions.action', $sub), ['action' => 'cancel'])->assertSessionHasErrors('reason');
        $this->actingAs($this->admin())->post(route('admin.subscriptions.action', $sub), ['action' => 'cancel', 'reason' => 'Fraud'])->assertSessionHas('success');
        $this->assertSame('cancelled', $sub->fresh()->status);
    }

    public function test_scheduled_seller_campaign_can_be_stopped_but_a_sent_one_cannot(): void
    {
        $seller = $this->seller();
        $campaign = \App\Models\MarketingCampaign::create(['seller_id' => $seller->id, 'channel' => 'email', 'name' => 'Spam', 'subject' => 'Buy', 'body' => 'x', 'audience' => [], 'status' => 'scheduled', 'scheduled_at' => now()->addDay()]);
        $sent = \App\Models\MarketingCampaign::create(['seller_id' => $seller->id, 'channel' => 'email', 'name' => 'Old', 'subject' => 'Buy', 'body' => 'x', 'audience' => [], 'status' => 'sent']);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.campaigns.stop', $campaign), ['reason' => 'Spam'])->assertSessionHas('success');
        $this->assertSame('draft', $campaign->fresh()->status);
        $this->actingAs($admin)->post(route('admin.campaigns.stop', $sent), ['reason' => 'Spam'])->assertSessionHas('error');
    }

    public function test_affiliate_can_be_removed_and_pending_commission_reversed(): void
    {
        $seller = $this->seller();
        $program = \App\Models\AffiliateProgram::create(['seller_id' => $seller->id, 'is_active' => true, 'commission_pct' => 10, 'cookie_days' => 30, 'auto_approve' => true]);
        $affiliate = \App\Models\Affiliate::create(['affiliate_program_id' => $program->id, 'name' => 'Ann', 'email' => 'a@example.com', 'status' => 'approved', 'earnings' => 10, 'conversions' => 1]);
        $line = $this->line($seller);
        $conversion = \App\Models\AffiliateConversion::create(['affiliate_id' => $affiliate->id, 'order_id' => $line->order_id, 'order_value' => 100, 'commission' => 10, 'status' => 'pending']);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.affiliates.void', $conversion), ['reason' => 'Self purchase'])->assertSessionHas('success');
        $this->assertSame('reversed', $conversion->fresh()->status);
        $this->assertEquals(0, (float) $affiliate->fresh()->earnings);
        $this->actingAs($admin)->post(route('admin.affiliates.void', $conversion), ['reason' => 'Again'])->assertSessionHas('error');

        $this->actingAs($admin)->post(route('admin.affiliates.status', $affiliate), ['status' => 'rejected', 'reason' => 'Fraud'])->assertSessionHas('success');
        $this->assertSame('rejected', $affiliate->fresh()->status);
    }

    public function test_insights_numbers_are_correct(): void
    {
        $seller = $this->seller();
        $other = $this->seller();
        $buyer = User::factory()->create();

        $this->line($seller, $buyer)->order->update(['status' => OrderStatus::Delivered, 'shipping_address' => ['state' => 'tx']]);
        $this->line($seller, $buyer)->order->update(['status' => OrderStatus::Delivered, 'shipping_address' => ['state' => 'TX']]);
        $this->line($other)->order->update(['status' => OrderStatus::Delivered, 'shipping_address' => ['state' => 'CA']]);
        $this->line($other, null, ['status' => OrderStatus::Refunded]);
        $this->product($seller);

        $res = $this->actingAs($this->admin())->get(route('admin.insights.index'))->assertOk();

        $this->assertSame(1, $res->viewData('loyalty')['repeat']);
        $this->assertSame('TX', $res->viewData('patterns')['states'][0]['label']);
        $this->assertEquals(200.0, $res->viewData('patterns')['states'][0]['value']);
        $this->assertSame(2, $res->viewData('concentration')['sellers']);

        $refund = collect($res->viewData('health'))->firstWhere('label', 'Refund rate');
        $this->assertEquals(25.0, $refund['value']);
        $this->assertSame('poor', $refund['rating']);

        $issue = collect($res->viewData('issues'))->firstWhere('filter', 'no_brand');
        $this->assertGreaterThanOrEqual(1, $issue['count']);

        $this->actingAs($this->admin())->get(route('admin.products.index', ['quality' => 'no_brand']))->assertOk()->assertSee('Slotted Rotor');
        $this->actingAs($this->admin('support_agent'))->get(route('admin.insights.index'))->assertForbidden();
    }

    public function test_empty_marketplace_shows_no_data_instead_of_dividing_by_zero(): void
    {
        $res = $this->actingAs($this->admin())->get(route('admin.insights.index'))->assertOk();

        $this->assertSame('none', collect($res->viewData('health'))->firstWhere('label', 'Refund rate')['rating']);
        $this->assertSame(0.0, $res->viewData('concentration')['top_share']);
    }
}
