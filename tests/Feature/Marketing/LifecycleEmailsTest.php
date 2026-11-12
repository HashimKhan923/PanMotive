<?php

namespace Tests\Feature\Marketing;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\MarketingUnsubscribe;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\StockAlert;
use App\Models\User;
use App\Notifications\BackInStock;
use App\Notifications\BrowseReminder;
use App\Notifications\DidItFit;
use App\Notifications\GarageTip;
use App\Notifications\WinBack;
use App\Services\Marketing\LifecycleEmailService;
use App\Support\EmailPreferences;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Panmotive's automatic buyer emails: back in stock, browse reminder, add-your-car tip, did-it-fit, win-back. */
class LifecycleEmailsTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seller = User::factory()->create();
        $this->seller->assignRole('seller');
        SellerProfile::create(['user_id' => $this->seller->id, 'business_name' => 'Lee Parts', 'business_type' => 'llc', 'status' => 'approved', 'slug' => 'lee-'.uniqid()]);
    }

    private function product(int $qty = 5): Product
    {
        return Product::create([
            'seller_id' => $this->seller->id, 'category_id' => Category::firstOrCreate(['slug' => 'c'], ['name' => 'C'])->id,
            'title' => 'Filter '.Str::random(4), 'slug' => 'f-'.Str::random(8), 'price' => 25, 'quantity' => $qty, 'status' => 'active',
        ]);
    }

    private function order(User $buyer, array $attrs = [], array $item = []): Order
    {
        $order = Order::create(array_merge([
            'order_number' => 'PM-'.strtoupper(Str::random(10)), 'buyer_id' => $buyer->id, 'status' => OrderStatus::Delivered,
            'subtotal' => 25, 'shipping_total' => 0, 'discount_total' => 0, 'credits_used' => 0, 'total' => 25, 'placed_at' => now(),
            'shipping_address' => ['name' => 'B'], 'billing_address' => ['name' => 'B'],
        ], $attrs));
        $product = $this->product();
        $order->items()->create(array_merge([
            'seller_id' => $this->seller->id, 'product_id' => $product->id, 'product_title' => $product->title, 'quantity' => 1,
            'unit_price' => 25, 'line_total' => 25, 'commission_rate' => 10, 'platform_fee_amount' => 2.5, 'seller_payout_amount' => 22.5,
            'status' => OrderStatus::Delivered, 'delivered_at' => now()->subDays(4),
        ], $item));

        return $order;
    }

    public function test_back_in_stock_emails_once_when_stock_returns(): void
    {
        Notification::fake();
        $product = $this->product(0);
        $buyer = User::factory()->create();

        Livewire::actingAs($buyer)->test(\App\Livewire\Storefront\AddToCartButton::class, ['product' => $product])
            ->assertSee('Email me when it')->call('notifyMe')->assertSee('We\'ll email you when it\'s back', false);
        $this->assertDatabaseHas('stock_alerts', ['user_id' => $buyer->id, 'product_id' => $product->id, 'notified_at' => null]);

        $product->update(['quantity' => 3]);
        $product->update(['quantity' => 5]); // still in stock: no second email

        Notification::assertSentToTimes($buyer, BackInStock::class, 1);
        $this->assertNotNull(StockAlert::first()->notified_at);

        // Guests are sent to log in first.
        auth()->logout();
        Livewire::test(\App\Livewire\Storefront\AddToCartButton::class, ['product' => $this->product(0)])
            ->call('notifyMe')->assertRedirect(route('login'));
    }

    public function test_browse_reminder_skips_bought_carted_and_recently_reminded(): void
    {
        Notification::fake();
        $buyer = User::factory()->create();
        $viewed = $this->product();
        $carted = $this->product();
        foreach ([$viewed, $carted] as $p) {
            DB::table('product_views')->insert(['product_id' => $p->id, 'viewer_key' => 'u'.$buyer->id, 'viewed_at' => now()->subHours(5), 'created_at' => now(), 'updated_at' => now()]);
        }
        $cartId = DB::table('carts')->insertGetId(['user_id' => $buyer->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cart_items')->insert(['cart_id' => $cartId, 'product_id' => $carted->id, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $service = app(LifecycleEmailService::class);
        $this->assertSame(1, $service->browseReminders());
        Notification::assertSentTo($buyer, BrowseReminder::class, fn ($n) => $n->products->pluck('id')->all() === [$viewed->id]);

        $this->assertSame(0, $service->browseReminders()); // once a week at most

        // Unsubscribed from all marketing: nothing.
        $other = User::factory()->create();
        DB::table('product_views')->insert(['product_id' => $viewed->id, 'viewer_key' => 'u'.$other->id, 'viewed_at' => now()->subHours(3), 'created_at' => now(), 'updated_at' => now()]);
        MarketingUnsubscribe::create(['user_id' => $other->id, 'seller_id' => null, 'source' => 'link']);
        $this->assertSame(0, $service->browseReminders());
    }

    public function test_garage_tip_goes_once_to_new_people_without_a_vehicle(): void
    {
        Notification::fake();
        $new = User::factory()->create(['created_at' => now()->subDays(3)]);
        $hasCar = User::factory()->create(['created_at' => now()->subDays(3), 'active_vehicle' => ['id' => 1, 'label' => '2018 Camry']]);
        $staff = User::factory()->create(['created_at' => now()->subDays(3)]);
        $staff->assignRole('super_admin');
        $optedOut = User::factory()->create(['created_at' => now()->subDays(3)]);
        EmailPreferences::set($optedOut, 'tips', false);

        $service = app(LifecycleEmailService::class);
        $this->assertSame(1, $service->garageTips());
        $this->assertSame(0, $service->garageTips());
        Notification::assertSentTo($new, GarageTip::class);
        Notification::assertNotSentTo([$hasCar, $staff, $optedOut], GarageTip::class);
    }

    public function test_did_it_fit_goes_once_per_order_and_skips_reviewed_or_returned_parts(): void
    {
        Notification::fake();
        $buyer = User::factory()->create();
        $order = $this->order($buyer);
        $reviewed = $this->order($buyer);
        DB::table('reviews')->insert(['product_id' => $reviewed->items->first()->product_id, 'user_id' => $buyer->id, 'order_item_id' => $reviewed->items->first()->id,
            'rating' => 5, 'body' => 'Great', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        $this->order($buyer, [], ['status' => OrderStatus::ReturnRequested]);
        $this->order($buyer, [], ['delivered_at' => now()->subDay()]); // too soon

        $service = app(LifecycleEmailService::class);
        $this->assertSame(1, $service->fitChecks());
        $this->assertSame(0, $service->fitChecks());
        Notification::assertSentTo($buyer, DidItFit::class, fn ($n) => $n->order->is($order));
        Notification::assertSentToTimes($buyer, DidItFit::class, 1);

        $html = (string) (new DidItFit($order, $order->items))->toMail($buyer)->render();
        $this->assertStringContainsString('Did it fit and work?', $html);
        $this->assertStringContainsString('Turn off “Did it fit?” emails', $html);
    }

    public function test_win_back_after_90_quiet_days_with_parts_for_their_vehicle(): void
    {
        Notification::fake();
        $make = \App\Models\VehicleMake::create(['name' => 'Toyota', 'slug' => 'toyota']);
        $model = \App\Models\VehicleModel::create(['vehicle_make_id' => $make->id, 'name' => 'Camry', 'slug' => 'camry']);
        $camry = \App\Models\Vehicle::create(['vehicle_make_id' => $make->id, 'vehicle_model_id' => $model->id, 'year' => 2018]);
        $fits = $this->product();
        $fits->fitments()->attach($camry->id);

        $lapsed = User::factory()->create(['active_vehicle' => ['id' => $camry->id, 'label' => '2018 Toyota Camry']]);
        $this->order($lapsed)->forceFill(['created_at' => now()->subDays(120)])->save();
        $recent = User::factory()->create();
        $this->order($recent);

        $service = app(LifecycleEmailService::class);
        $this->assertSame(1, $service->winBacks());
        $this->assertSame(0, $service->winBacks());
        Notification::assertSentTo($lapsed, WinBack::class, fn ($n) => $n->vehicle === '2018 Toyota Camry' && $n->products->pluck('id')->contains($fits->id));
        Notification::assertNotSentTo($recent, WinBack::class);
    }

    public function test_new_email_types_appear_on_the_preferences_page(): void
    {
        $this->actingAs(User::factory()->create())->get(route('marketing.preferences'))->assertOk()
            ->assertSee('Back in stock')->assertSee('Parts you looked at')->assertSee('Did it fit?')->assertSee('Picks for your vehicle');
    }
}
