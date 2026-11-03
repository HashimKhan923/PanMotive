<?php

namespace Tests\Feature\Seller;

use App\Livewire\Seller\AdManager;
use App\Models\AdCampaign;
use App\Models\AdCreative;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Seller\AdServingService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seller(float $credit = 50): User
    {
        $u = User::factory()->create();
        $u->assignRole('seller');
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'Ad '.uniqid(), 'business_type' => 'llc', 'status' => 'approved', 'ad_credit' => $credit]);

        return $this->withTwoFactor($u);
    }

    private function product(User $s, ?int $catId = null): Product
    {
        return Product::create([
            'seller_id' => $s->id,
            'category_id' => $catId ?? Category::create(['name' => 'C'.uniqid(), 'slug' => 'c-'.uniqid()])->id,
            'title' => 'Rotor '.uniqid(), 'slug' => 'r-'.uniqid(),
            'price' => 120, 'quantity' => 10, 'status' => 'active',
        ]);
    }

    public function test_create_targeted_campaign_with_creative(): void
    {
        $seller = $this->seller();
        $cat = Category::create(['name' => 'Brakes', 'slug' => 'brakes-'.uniqid()]);
        $product = $this->product($seller, $cat->id);

        Livewire::actingAs($seller)->test(AdManager::class)
            ->call('newCampaign')
            ->set('name', 'Brake push')
            ->set('dailyBudget', 20)
            ->set('placements', ['search', 'category'])
            ->set('categories', [$cat->id])
            ->set('keywords', 'brake, rotor')
            ->set('creatives.0.product_id', $product->id)
            ->set('creatives.0.headline', 'Stop faster')
            ->call('save')
            ->assertHasNoErrors();

        $campaign = AdCampaign::firstOrFail();
        $this->assertSame('active', $campaign->status);
        $this->assertSame(['brake', 'rotor'], $campaign->targeting['keywords']);
        $this->assertCount(1, $campaign->creatives);
    }

    public function test_serving_matches_category_and_charges_cpc_on_click(): void
    {
        $seller = $this->seller(10);
        $cat = Category::create(['name' => 'Filters', 'slug' => 'filters-'.uniqid()]);
        $product = $this->product($seller, $cat->id);

        $campaign = AdCampaign::create([
            'seller_id' => $seller->id, 'name' => 'c', 'objective' => 'sales', 'status' => 'active',
            'daily_budget' => 5, 'targeting' => ['placements' => ['search'], 'categories' => [$cat->id], 'vehicle_make_ids' => [], 'keywords' => []],
            'starts_at' => now(),
        ]);
        $creative = $campaign->creatives()->create(['product_id' => $product->id, 'headline' => 'x', 'is_active' => true]);

        $ads = app(AdServingService::class);
        $served = $ads->forPlacement('search', ['category_id' => $cat->id]);
        $this->assertTrue($served->contains(fn ($s) => $s['ref_id'] === $creative->id && $s['source'] === 'creative'));

        $ads->recordImpressions($served);
        $this->assertSame(1, $creative->fresh()->impressions);

        $ads->recordClick('creative', $creative->id);
        $this->assertSame(1, $creative->fresh()->clicks);
        $this->assertEquals(9.75, (float) $seller->sellerProfile->fresh()->ad_credit); // -$0.25 search CPC
        $this->assertEquals(0.25, (float) $campaign->fresh()->total_spend);
    }

    public function test_campaign_pauses_when_ad_credit_runs_out(): void
    {
        $seller = $this->seller(0.10);
        $product = $this->product($seller);
        $campaign = AdCampaign::create([
            'seller_id' => $seller->id, 'name' => 'c', 'objective' => 'sales', 'status' => 'active',
            'daily_budget' => 5, 'targeting' => ['placements' => ['search'], 'categories' => [], 'vehicle_make_ids' => [], 'keywords' => []],
            'starts_at' => now(),
        ]);
        $creative = $campaign->creatives()->create(['product_id' => $product->id, 'is_active' => true]);

        app(AdServingService::class)->recordClick('creative', $creative->id);

        $this->assertSame('paused', $campaign->fresh()->status);
    }

    public function test_ad_click_route_records_and_redirects(): void
    {
        $seller = $this->seller();
        $product = $this->product($seller);
        $campaign = AdCampaign::create([
            'seller_id' => $seller->id, 'name' => 'c', 'objective' => 'sales', 'status' => 'active',
            'daily_budget' => 5, 'targeting' => ['placements' => ['search'], 'categories' => [], 'vehicle_make_ids' => [], 'keywords' => []],
            'starts_at' => now(),
        ]);
        $creative = $campaign->creatives()->create(['product_id' => $product->id, 'is_active' => true]);

        $this->get(route('ad.click', ['source' => 'creative', 'ref' => $creative->id, 'p' => $product->id]))
            ->assertRedirect(route('products.show', $product->slug))
            ->assertCookie('pm_ad', "creative:{$creative->id}");

        $this->assertSame(1, $creative->fresh()->clicks);
    }
}
