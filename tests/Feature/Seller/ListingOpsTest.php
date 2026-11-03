<?php

namespace Tests\Feature\Seller;

use App\Livewire\Seller\Bundles as BundlesComponent;
use App\Livewire\Seller\ProductForm;
use App\Livewire\Seller\ProductList;
use App\Models\Bundle;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductTemplate;
use App\Models\RepricingRule;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\VolumeDiscount;
use App\Services\Cart\CartService;
use App\Services\Seller\InsightsService;
use App\Services\Seller\RepricingService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ListingOpsTest extends TestCase
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
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'Acme '.uniqid(), 'business_type' => 'llc', 'status' => 'approved']);

        return $this->withTwoFactor($u);
    }

    private function product(User $s, array $o = []): Product
    {
        return Product::create(array_merge([
            'seller_id' => $s->id,
            'category_id' => Category::create(['name' => 'C'.uniqid(), 'slug' => 'c-'.uniqid()])->id,
            'title' => 'Rotor', 'slug' => 'rotor-'.uniqid(),
            'price' => 100, 'quantity' => 20, 'status' => 'active',
        ], $o));
    }

    public function test_bulk_price_adjust_and_inline_edit_and_template(): void
    {
        $seller = $this->seller();
        $p1 = $this->product($seller, ['price' => 100]);
        $p2 = $this->product($seller, ['price' => 200]);

        Livewire::actingAs($seller)->test(ProductList::class)
            ->set('selected', [(string) $p1->id, (string) $p2->id])
            ->set('priceMode', 'pct_down')->set('priceValue', 10)
            ->call('applyBulkPrice');

        $this->assertEquals(90.0, (float) $p1->fresh()->price);
        $this->assertEquals(180.0, (float) $p2->fresh()->price);

        Livewire::actingAs($seller)->test(ProductList::class)
            ->call('startEdit', $p1->id)
            ->set('editPrice', 77.5)->set('editQty', 3)
            ->call('saveEdit');
        $this->assertEquals(77.5, (float) $p1->fresh()->price);
        $this->assertSame(3, $p1->fresh()->quantity);

        Livewire::actingAs($seller)->test(ProductList::class)->call('saveAsTemplate', $p2->id);
        $this->assertDatabaseHas('product_templates', ['seller_id' => $seller->id]);
    }

    public function test_csv_export_streams_catalog(): void
    {
        $seller = $this->seller();
        $this->product($seller, ['title' => 'Exported Rotor']);

        Livewire::actingAs($seller)->test(ProductList::class)
            ->call('exportCsv')
            ->assertFileDownloaded();
    }

    public function test_product_form_saves_volume_tiers_and_repricing_rule(): void
    {
        $seller = $this->seller();
        $cat = Category::create(['name' => 'Belts', 'slug' => 'b-'.uniqid()]);

        Livewire::actingAs($seller)->test(ProductForm::class)
            ->set('title', 'Serpentine Belt')->set('category_id', $cat->id)
            ->set('price', 40)->set('quantity', 30)
            ->call('addVolumeTier')
            ->set('volumeTiers.0.min_quantity', 4)
            ->set('volumeTiers.0.discount_type', 'percentage')
            ->set('volumeTiers.0.discount_value', 12)
            ->set('reprice_enabled', true)
            ->set('reprice_strategy', 'beat_lowest')
            ->set('reprice_amount', 1.00)
            ->set('reprice_floor', 25)
            ->call('submit')
            ->assertHasNoErrors();

        $product = Product::where('title', 'Serpentine Belt')->firstOrFail();
        $this->assertDatabaseHas('volume_discounts', ['product_id' => $product->id, 'min_quantity' => 4]);
        $this->assertDatabaseHas('repricing_rules', ['product_id' => $product->id, 'strategy' => 'beat_lowest']);

        // 4+ qty gets the tier price
        $this->assertEquals(35.2, $product->unitPriceForQuantity(4)); // 40 - 12%
        $this->assertEquals(40.0, $product->unitPriceForQuantity(1));
    }

    public function test_repricing_service_matches_lowest_competitor_within_floor(): void
    {
        $a = $this->seller();
        $b = $this->seller();
        $cat = Category::create(['name' => 'Plugs', 'slug' => 'p-'.uniqid()]);

        $mine = Product::create(['seller_id' => $a->id, 'category_id' => $cat->id, 'title' => 'Plug', 'slug' => 'pl-'.uniqid(), 'price' => 30, 'quantity' => 5, 'status' => 'active', 'part_number' => 'SP-1']);
        Product::create(['seller_id' => $b->id, 'category_id' => $cat->id, 'title' => 'Plug', 'slug' => 'pl-'.uniqid(), 'price' => 22, 'quantity' => 5, 'status' => 'active', 'part_number' => 'SP-1']);

        $rule = RepricingRule::create(['product_id' => $mine->id, 'seller_id' => $a->id, 'strategy' => 'beat_lowest', 'amount' => 1.00, 'floor_price' => 24.00, 'is_active' => true]);

        $result = app(RepricingService::class)->apply($rule);

        // lowest 22, beat by 1 = 21, but floor 24 wins
        $this->assertEquals(24.00, (float) $mine->fresh()->price);
        $this->assertStringContainsString('24.00', $result);
    }

    public function test_bundle_discount_applies_when_full_set_in_cart(): void
    {
        $seller = $this->seller();
        $buyer = User::factory()->create();
        $pads = $this->product($seller, ['title' => 'Pads', 'price' => 60]);
        $rotors = $this->product($seller, ['title' => 'Rotors', 'price' => 120]);

        Livewire::actingAs($seller)->test(BundlesComponent::class)
            ->call('newBundle')
            ->set('title', 'Front brake job')
            ->set('discount_type', 'percentage')->set('discount_value', 10)
            ->set('lines', [
                ['product_id' => $pads->id, 'quantity' => 1],
                ['product_id' => $rotors->id, 'quantity' => 1],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('bundles', ['title' => 'Front brake job']);

        $this->actingAs($buyer);
        $cart = app(CartService::class);
        $cart->add($pads->id, 1);
        $this->assertEquals(0.0, $cart->bundleDiscount()['total']); // rotors missing

        $cart->add($rotors->id, 1);
        // full price 180, 10% off = 18
        $this->assertEquals(18.0, $cart->bundleDiscount()['total']);
    }

    public function test_insights_flags_stale_listing_and_one_click_sale(): void
    {
        $seller = $this->seller();
        $stale = $this->product($seller, ['price' => 100]);
        $stale->forceFill(['created_at' => now()->subDays(90)])->save();

        $flagged = app(InsightsService::class)->staleListings($seller->id);
        $this->assertTrue($flagged->contains('id', $stale->id));

        $this->actingAs($seller)->from(route('seller.insights.index'))
            ->post(route('seller.insights.run-sale', $stale), ['pct' => 20, 'days' => 10])
            ->assertRedirect();

        $stale->refresh();
        $this->assertEquals(80.0, (float) $stale->sale_price);
        $this->assertNotNull($stale->deal_ends_at);
    }
}
