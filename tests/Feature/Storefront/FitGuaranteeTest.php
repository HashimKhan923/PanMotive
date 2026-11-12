<?php

namespace Tests\Feature\Storefront;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\FitGuarantee;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** The Panmotive Fit Guarantee: what's covered, what the buyer sees, and what the seller can't do. */
class FitGuaranteeTest extends TestCase
{
    use RefreshDatabase;

    private Vehicle $camry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $make = VehicleMake::create(['name' => 'Toyota', 'slug' => 'toyota']);
        $model = VehicleModel::create(['vehicle_make_id' => $make->id, 'name' => 'Camry', 'slug' => 'camry']);
        $this->camry = Vehicle::create(['vehicle_make_id' => $make->id, 'vehicle_model_id' => $model->id, 'year' => 2018]);
    }

    private function seller(): User
    {
        $u = User::factory()->create();
        $u->assignRole('seller');
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'Shop '.uniqid(), 'business_type' => 'llc', 'status' => 'approved', 'slug' => 's-'.uniqid()]);

        return $this->withTwoFactor($u);
    }

    private function product(User $seller, bool $fitsCamry): Product
    {
        $p = Product::create([
            'seller_id' => $seller->id, 'category_id' => Category::firstOrCreate(['slug' => 'brakes'], ['name' => 'Brakes'])->id,
            'title' => 'Brake pads '.Str::random(4), 'slug' => 'pads-'.Str::random(8), 'price' => 60, 'quantity' => 10, 'status' => 'active',
        ]);
        if ($fitsCamry) {
            $p->fitments()->attach($this->camry->id);
        }

        return $p;
    }

    private function inGarage(): static
    {
        return $this->withSession(['garage.vehicle' => ['id' => $this->camry->id, 'label' => '2018 Toyota Camry']]);
    }

    private function deliveredLine(User $seller, User $buyer, bool $confirmed): OrderItem
    {
        $product = $this->product($seller, $confirmed);
        $order = Order::create([
            'order_number' => 'PM-'.strtoupper(Str::random(10)), 'buyer_id' => $buyer->id, 'status' => OrderStatus::Delivered,
            'subtotal' => 60, 'shipping_total' => 0, 'discount_total' => 0, 'credits_used' => 0, 'total' => 60, 'placed_at' => now(),
            'shipping_address' => $address = ['name' => 'B', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country' => 'US'],
            'billing_address' => $address,
        ]);

        return $order->items()->create([
            'seller_id' => $seller->id, 'product_id' => $product->id, 'product_title' => $product->title, 'quantity' => 1,
            'unit_price' => 60, 'line_total' => 60, 'commission_rate' => 10, 'platform_fee_amount' => 6, 'seller_payout_amount' => 54,
            'status' => OrderStatus::Delivered, 'delivered_at' => now()->subDay(), 'shipped_at' => now()->subDays(3),
            'fit_vehicle_id' => $this->camry->id, 'fit_vehicle_label' => '2018 Toyota Camry', 'fit_confirmed' => $confirmed,
        ]);
    }

    public function test_checkout_freezes_which_car_and_whether_the_listing_covered_it(): void
    {
        $seller = $this->seller();
        $fits = $this->product($seller, true);
        $other = $this->product($seller, false);

        session(['garage.vehicle' => ['id' => $this->camry->id, 'label' => '2018 Toyota Camry']]);
        $guarantee = app(FitGuarantee::class);

        $this->assertSame(['fit_vehicle_id' => $this->camry->id, 'fit_vehicle_label' => '2018 Toyota Camry', 'fit_confirmed' => true], $guarantee->snapshot($fits));
        $this->assertFalse($guarantee->snapshot($other)['fit_confirmed']);

        $check = $guarantee->check(collect([['product' => $fits], ['product' => $other]]));
        $this->assertSame(['vehicle' => '2018 Toyota Camry', 'covered' => 1, 'total' => 2, 'lines' => [true, false]], $check);

        session()->forget('garage.vehicle');
        $this->assertSame(['fit_vehicle_id' => null, 'fit_vehicle_label' => null, 'fit_confirmed' => false], app(FitGuarantee::class)->snapshot($fits));
    }

    public function test_checkout_shows_the_fit_check_for_the_buyers_car(): void
    {
        $seller = $this->seller();
        $fits = $this->product($seller, true);
        $other = $this->product($seller, false);
        $buyer = User::factory()->create();

        $this->actingAs($buyer)->post(route('cart.add'), ['product_id' => $fits->id, 'quantity' => 1]);
        $this->actingAs($buyer)->get(route('checkout.index'))->assertOk()->assertSee('Check the fit before you pay');

        $this->inGarage()->actingAs($buyer)->get(route('checkout.index'))->assertOk()
            ->assertSee('Everything fits your 2018 Toyota Camry')->assertSee('Fits · Fit Guarantee');

        $this->actingAs($buyer)->post(route('cart.add'), ['product_id' => $other->id, 'quantity' => 1]);
        $this->inGarage()->actingAs($buyer)->get(route('checkout.index'))->assertOk()
            ->assertSee('1 of 2 parts confirmed for your 2018 Toyota Camry')->assertSee('Fit not confirmed');
    }

    public function test_the_product_page_and_guarantee_page_explain_it(): void
    {
        $product = $this->product($this->seller(), true);

        $this->inGarage()->get(route('products.show', $product->slug))->assertOk()
            ->assertSee('Fits your 2018 Toyota Camry')->assertSee('Panmotive Fit Guarantee:')->assertSee('free return shipping');

        $this->get(route('fit-guarantee'))->assertOk()->assertSee('Fit Guarantee')->assertSee('The seller pays for it');
        $this->get(config('app.url').'/')->assertOk()->assertSee(route('fit-guarantee'), false);
    }

    public function test_a_guaranteed_return_is_marked_and_the_seller_cannot_just_refuse_it(): void
    {
        Notification::fake();
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $item = $this->deliveredLine($seller, $buyer, true);

        $this->actingAs($buyer)->get(route('orders.show', $item->order))->assertOk()
            ->assertSee(FitGuarantee::REASON)->assertSee('Listed as fitting your 2018 Toyota Camry', false);

        $this->actingAs($buyer)->post(route('orders.return', $item), ['reason' => FitGuarantee::REASON])->assertRedirect();
        $rma = ReturnRequest::firstOrFail();
        $this->assertTrue($rma->fit_guarantee);

        $this->actingAs($seller)->get(route('seller.returns.index'))->assertOk()
            ->assertSee('Fit Guarantee return.')->assertSee('Disagree — ask Panmotive to decide');

        // "Decline" becomes a Panmotive review, not a refusal.
        $this->actingAs($seller)->post(route('seller.returns.update', $rma), ['decision' => 'reject', 'note' => 'It fits, buyer installed it wrong'])
            ->assertRedirect()->assertSessionHas('success', fn ($m) => str_contains($m, 'Panmotive will review'));
        $rma->refresh();
        $this->assertSame('requested', $rma->status);
        $this->assertNotNull($rma->escalated_at);
        $this->assertDatabaseHas('admin_alerts', ['type' => 'fit_guarantee_dispute', 'related_order_item_id' => $item->id]);

        // It counts against the seller's fitment record once there's enough to judge.
        foreach (range(1, 5) as $i) {
            $this->deliveredLine($seller, $buyer, true);
        }
        $record = app(FitGuarantee::class)->sellerRecord($seller->id);
        $this->assertSame(1, $record['returns']);
        $this->assertSame(6, $record['sold']);
        $this->assertSame(16.7, $record['rate']);
    }

    public function test_parts_not_listed_for_the_car_are_not_covered_even_with_the_same_reason(): void
    {
        Notification::fake();
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $item = $this->deliveredLine($seller, $buyer, false);

        $this->actingAs($buyer)->get(route('orders.show', $item->order))->assertOk()
            ->assertDontSee(FitGuarantee::REASON)->assertSee(FitGuarantee::WRONG_ORDER_REASON);

        $this->actingAs($buyer)->post(route('orders.return', $item), ['reason' => FitGuarantee::REASON])->assertRedirect();
        $this->assertFalse(ReturnRequest::firstOrFail()->fit_guarantee);

        // A normal return can still be declined as before.
        $this->actingAs($seller)->post(route('seller.returns.update', ReturnRequest::first()), ['decision' => 'reject', 'note' => 'Installed and damaged'])
            ->assertSessionHas('success', 'Return declined — the buyer has been told why.');
        $this->assertSame('rejected', ReturnRequest::first()->status);
    }
}
