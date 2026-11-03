<?php

namespace Tests\Feature\Seller;

use App\Livewire\Seller\Inventory;
use App\Models\Category;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\StockMovement;
use App\Models\User;
use App\Notifications\LowStockAlert;
use App\Services\Seller\InventoryService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class InventoryDepthTest extends TestCase
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
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'Inv '.uniqid(), 'business_type' => 'llc', 'status' => 'approved', 'slug' => 'inv-'.uniqid()]);

        return $this->withTwoFactor($u);
    }

    private function product(User $seller, array $attrs = []): Product
    {
        return Product::create(array_merge([
            'seller_id' => $seller->id,
            'category_id' => Category::create(['name' => 'C'.uniqid(), 'slug' => 'c-'.uniqid()])->id,
            'title' => 'SKU '.uniqid(), 'slug' => 's-'.uniqid(),
            'price' => 30, 'quantity' => 20, 'status' => 'active',
        ], $attrs));
    }

    public function test_adjust_logs_a_movement_and_updates_quantity(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller, ['quantity' => 20]);

        $this->actingAs($seller); // populate SellerContext actor
        app(InventoryService::class)->adjust($product, -5, 'adjustment', ['note' => 'damaged']);

        $this->assertSame(15, (int) $product->fresh()->quantity);
        $m = StockMovement::where('product_id', $product->id)->latest()->first();
        $this->assertSame(-5, $m->delta);
        $this->assertSame('adjustment', $m->reason);
        $this->assertSame(15, $m->balance_after);
    }

    public function test_multi_location_quantity_is_the_sum_of_locations(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller, ['quantity' => 0]);
        $west = InventoryLocation::create(['seller_id' => $seller->id, 'name' => 'West', 'is_default' => true]);
        $east = InventoryLocation::create(['seller_id' => $seller->id, 'name' => 'East']);

        $svc = app(InventoryService::class);
        $svc->adjust($product, 12, 'restock', ['location_id' => $west->id]);
        $svc->adjust($product, 8, 'restock', ['location_id' => $east->id]);

        $this->assertSame(20, (int) $product->fresh()->quantity);
    }

    public function test_low_stock_sweep_notifies_once(): void
    {
        Notification::fake();
        $seller = $this->seller();
        $this->product($seller, ['quantity' => 2, 'reorder_point' => 5]);
        $this->product($seller, ['quantity' => 50, 'reorder_point' => 5]); // healthy

        $this->assertSame(1, app(InventoryService::class)->sweepLowStock());
        Notification::assertSentTo($seller, LowStockAlert::class);

        // second sweep is quiet (already flagged)
        $this->assertSame(0, app(InventoryService::class)->sweepLowStock());
    }

    public function test_restocking_clears_the_low_stock_flag(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller, ['quantity' => 2, 'reorder_point' => 5, 'reorder_qty' => 30]);
        app(InventoryService::class)->sweepLowStock();
        $this->assertNotNull($product->fresh()->low_stock_notified_at);

        app(InventoryService::class)->adjust($product, 40, 'restock');
        $this->assertNull($product->fresh()->low_stock_notified_at);
    }

    public function test_inventory_component_adjust_and_gate(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller, ['quantity' => 10]);

        Livewire::actingAs($seller)->test(Inventory::class)
            ->call('openAdjust', $product->id)
            ->set('adjustDelta', 7)
            ->set('adjustReason', 'restock')
            ->call('applyAdjust')
            ->assertHasNoErrors();

        $this->assertSame(17, (int) $product->fresh()->quantity);

        // a staff member without inventory.manage is blocked
        $staff = $this->withTwoFactor(User::factory()->create(['email' => 'ni@example.com']));
        \App\Models\SellerTeamMember::create([
            'owner_id' => $seller->id, 'user_id' => $staff->id, 'email' => 'ni@example.com',
            'role' => 'viewer', 'abilities' => ['products.view'], 'status' => 'active',
        ]);
        $this->actingAs($staff)->get(route('seller.inventory.index'))->assertForbidden();
    }
}
