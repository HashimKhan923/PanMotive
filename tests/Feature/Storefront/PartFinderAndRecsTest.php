<?php

namespace Tests\Feature\Storefront;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductView;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Recommendations\RecommendationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PartFinderAndRecsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function product(): Product
    {
        $seller = User::factory()->create();
        $seller->assignRole('seller');
        SellerProfile::create(['user_id' => $seller->id, 'business_name' => 'R '.uniqid(), 'business_type' => 'llc', 'status' => 'approved', 'slug' => 'r-'.uniqid()]);

        return Product::create([
            'seller_id' => $seller->id,
            'category_id' => Category::create(['name' => 'C'.uniqid(), 'slug' => 'c-'.uniqid()])->id,
            'title' => 'Part '.uniqid(), 'slug' => 'p-'.uniqid(),
            'price' => 50, 'quantity' => 10, 'status' => 'active',
        ]);
    }

    public function test_find_parts_page_renders(): void
    {
        Category::create(['name' => 'Brakes', 'slug' => 'brakes-'.uniqid(), 'is_active' => true]);

        // Nothing for sale yet: no part-type links that lead to an empty page.
        $this->get(route('find-parts'))
            ->assertOk()
            ->assertSee('Find parts that fit')
            ->assertDontSee('Shop by part type');

        $this->product();
        cache()->forget('shop.category_roots');

        $this->get(route('find-parts'))->assertOk()->assertSee('Shop by part type');
    }

    public function test_visiting_a_pdp_logs_a_product_view(): void
    {
        $product = $this->product();

        $this->get(route('products.show', $product->slug))->assertOk();

        $this->assertDatabaseHas('product_views', ['product_id' => $product->id]);
    }

    public function test_also_viewed_is_derived_from_co_view_history(): void
    {
        $a = $this->product();
        $b = $this->product();
        $c = $this->product();

        // two shoppers each viewed A then B; one also viewed C
        foreach (['viewer-1', 'viewer-2'] as $key) {
            ProductView::create(['product_id' => $a->id, 'viewer_key' => $key, 'viewed_at' => now()]);
            ProductView::create(['product_id' => $b->id, 'viewer_key' => $key, 'viewed_at' => now()]);
        }
        ProductView::create(['product_id' => $c->id, 'viewer_key' => 'viewer-1', 'viewed_at' => now()]);

        $also = app(RecommendationService::class)->alsoViewed($a->id);

        $this->assertTrue($also->contains('id', $b->id));
        $this->assertSame($b->id, $also->first()->id); // B co-viewed more than C
        $this->assertFalse($also->contains('id', $a->id));
    }

    public function test_also_viewed_empty_without_history(): void
    {
        $this->assertTrue(app(RecommendationService::class)->alsoViewed($this->product()->id)->isEmpty());
    }
}
