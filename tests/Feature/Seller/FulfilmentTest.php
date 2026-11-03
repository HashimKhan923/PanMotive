<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Livewire\Seller\FitmentPicker;
use App\Livewire\Seller\ProductForm;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\ShippingLabel;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Seller\FitmentSuggestService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FulfilmentTest extends TestCase
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
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'F '.uniqid(), 'business_type' => 'llc', 'status' => 'approved']);

        return $this->withTwoFactor($u);
    }

    /** @return array{0:Order,1:array<int,OrderItem>} */
    private function twoItemOrder(User $seller, OrderStatus $status = OrderStatus::SellerAccepted): array
    {
        $cat = Category::create(['name' => 'C'.uniqid(), 'slug' => 'c-'.uniqid()]);
        $order = Order::create([
            'order_number' => 'PM-'.strtoupper(uniqid()), 'buyer_id' => User::factory()->create()->id,
            'subtotal' => 300, 'total' => 300,
            'shipping_address' => ['name' => 'Dana', 'line1' => '5 Elm', 'city' => 'Boise', 'state' => 'ID', 'postal_code' => '83702', 'country' => 'US'],
            'billing_address' => ['line1' => '5 Elm'], 'status' => OrderStatus::Paid,
        ]);
        $items = [];
        foreach (['Pads', 'Rotors'] as $t) {
            $p = Product::create(['seller_id' => $seller->id, 'category_id' => $cat->id, 'title' => $t, 'slug' => strtolower($t).'-'.uniqid(), 'price' => 150, 'quantity' => 5, 'status' => 'active', 'weight_lbs' => 4]);
            $items[] = $order->items()->create([
                'seller_id' => $seller->id, 'product_id' => $p->id, 'product_title' => $t,
                'quantity' => 1, 'unit_price' => 150, 'line_total' => 150, 'commission_rate' => 10,
                'platform_fee_amount' => 15, 'seller_payout_amount' => 135, 'status' => $status,
            ]);
        }

        return [$order, $items];
    }

    public function test_buy_label_ships_all_items_with_real_tracking_and_charges_postage(): void
    {
        config()->set('services.easypost.key', 'EZTK_test');
        \Illuminate\Support\Facades\Http::fake([
            'api.easypost.com/v2/shipments' => \Illuminate\Support\Facades\Http::response(['id' => 'shp_1', 'rates' => [
                ['id' => 'rate_ups', 'carrier' => 'UPS', 'service' => 'Ground', 'rate' => '9.80', 'retail_rate' => '14.10', 'delivery_days' => 3],
                ['id' => 'rate_usps', 'carrier' => 'USPS', 'service' => 'GroundAdvantage', 'rate' => '7.40', 'retail_rate' => '9.20', 'delivery_days' => 4],
            ]]),
            'api.easypost.com/v2/shipments/shp_1/buy' => \Illuminate\Support\Facades\Http::response([
                'tracking_code' => '1Z999AA10123456784',
                'postage_label' => ['label_url' => 'https://easypost-files.s3.amazonaws.com/label.png'],
                'selected_rate' => ['carrier' => 'UPS', 'service' => 'Ground', 'rate' => '9.80', 'retail_rate' => '14.10'],
            ]),
        ]);
        $seller = $this->seller();
        $seller->sellerProfile->update(['ship_from' => ['name' => 'Dock', 'line1' => '1 Shop Rd', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701']]);
        [$order, $items] = $this->twoItemOrder($seller);
        $payoutBefore = (float) collect($items)->sum(fn ($i) => $i->fresh()->seller_payout_amount);

        // Live rates, cheapest first.
        $this->actingAs($seller)->get(route('seller.fulfilment.label', $order))->assertOk()->assertSee('USPS')->assertSee('$7.40');

        $this->actingAs($seller)
            ->post(route('seller.fulfilment.buy-label', $order), ['rate_id' => 'rate_ups'])
            ->assertRedirect();

        $label = ShippingLabel::firstOrFail();
        $this->assertSame('UPS', $label->carrier);
        $this->assertSame('1Z999AA10123456784', $label->tracking_number);
        $this->assertSame('https://easypost-files.s3.amazonaws.com/label.png', $label->label_url);
        $this->assertCount(2, $label->items);

        foreach ($items as $item) {
            $item->refresh();
            $this->assertSame(OrderStatus::Shipped, $item->status);
            $this->assertSame('1Z999AA10123456784', $item->tracking_number);
        }
        // Postage is the seller's cost: $9.80 taken from this order's payout.
        $this->assertEquals(round($payoutBefore - 9.80, 2), round((float) collect($items)->sum(fn ($i) => $i->fresh()->seller_payout_amount), 2));
        $this->assertEquals(9.80, (float) collect($items)->sum(fn ($i) => $i->fresh()->label_fee));
    }

    public function test_without_a_label_provider_tracking_is_never_invented(): void
    {
        config()->set('services.easypost.key', null);
        $seller = $this->seller();
        [$order, $items] = $this->twoItemOrder($seller);

        $this->actingAs($seller)->get(route('seller.fulfilment.label', $order))->assertOk()->assertSee('isn\'t switched on yet', false);
        $this->actingAs($seller)->withSession(['label_shipment.'.$order->id => 'shp_x'])
            ->post(route('seller.fulfilment.buy-label', $order), ['rate_id' => 'rate_x'])
            ->assertSessionHasErrors('label');

        $this->assertSame(0, ShippingLabel::count());
        $this->assertSame(OrderStatus::SellerAccepted, $items[0]->fresh()->status);
    }

    public function test_manual_ship_all_together_applies_one_tracking(): void
    {
        $seller = $this->seller();
        [$order, $items] = $this->twoItemOrder($seller);

        $this->actingAs($seller)
            ->post(route('seller.orders.ship', $items[0]), [
                'carrier' => 'USPS', 'tracking_number' => '9400111899561234567890', 'ship_all' => 1,
            ])
            ->assertRedirect();

        foreach ($items as $item) {
            $this->assertSame(OrderStatus::Shipped, $item->fresh()->status);
            $this->assertSame('9400111899561234567890', $item->fresh()->tracking_number);
        }
    }

    public function test_pick_list_and_packing_slip_render(): void
    {
        $seller = $this->seller();
        [$order] = $this->twoItemOrder($seller);

        $this->actingAs($seller)->get(route('seller.fulfilment.pick-list'))->assertOk()->assertSee('Pick list');
        $this->actingAs($seller)->get(route('seller.fulfilment.packing-slip', $order))->assertOk()->assertSee('Packing slip')->assertSee('Dana');
    }

    public function test_packing_slip_denied_for_other_seller(): void
    {
        $seller = $this->seller();
        $other = $this->seller();
        [$order] = $this->twoItemOrder($seller);

        $this->actingAs($other)->get(route('seller.fulfilment.packing-slip', $order))->assertForbidden();
    }

    public function test_vin_decode_returns_year_and_make(): void
    {
        $make = VehicleMake::create(['name' => 'Honda', 'slug' => 'honda']);
        $model = VehicleModel::create(['vehicle_make_id' => $make->id, 'name' => 'Accord', 'slug' => 'accord']);
        $v = Vehicle::create(['vehicle_make_id' => $make->id, 'vehicle_model_id' => $model->id, 'year' => 2013]);

        // position 10 (index 9) = 'D' → 2013, WMI 1HG → Honda
        $result = app(FitmentSuggestService::class)->fromVin('1HGCM8263D3004352');

        $this->assertTrue($result['ok']);
        $this->assertSame(2013, $result['year']);
        $this->assertSame('Honda', $result['make']);
        $this->assertContains($v->id, $result['vehicle_ids']);
    }

    public function test_suggest_fitments_from_part_number_copies_from_peers(): void
    {
        $a = $this->seller();
        $b = $this->seller();
        $cat = Category::create(['name' => 'Filters', 'slug' => 'f-'.uniqid()]);

        $make = VehicleMake::create(['name' => 'Ford', 'slug' => 'ford']);
        $model = VehicleModel::create(['vehicle_make_id' => $make->id, 'name' => 'F-150', 'slug' => 'f150']);
        $veh = Vehicle::create(['vehicle_make_id' => $make->id, 'vehicle_model_id' => $model->id, 'year' => 2018]);

        $peer = Product::create(['seller_id' => $b->id, 'category_id' => $cat->id, 'title' => 'Air Filter', 'slug' => 'af-'.uniqid(), 'price' => 20, 'quantity' => 5, 'status' => 'active', 'part_number' => 'FA-1234']);
        $peer->fitments()->sync([$veh->id]);

        $suggested = app(FitmentSuggestService::class)->fromPartNumber('FA-1234');
        $this->assertTrue($suggested->contains($veh->id));
    }
}
