<?php

namespace Tests\Feature\Polish;

use App\Livewire\Storefront\AddToCartButton;
use App\Livewire\Storefront\VehicleSelector;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Cart\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class PolishTest extends TestCase
{
    use RefreshDatabase;

    private function product(): Product
    {
        return Product::create([
            'seller_id' => User::factory()->create()->id,
            'category_id' => Category::create(['name' => 'C', 'slug' => 'c-'.Str::random(5)])->id,
            'title' => 'Brake Pad Set', 'slug' => 'bp-'.Str::random(5),
            'price' => 59.99, 'quantity' => 5, 'status' => 'active',
        ]);
    }

    public function test_pwa_assets_exist_and_manifest_is_valid(): void
    {
        // Static files under public/ are served by the web server, not the
        // Laravel router — assert them on disk.
        foreach (['manifest.json', 'sw.js', 'offline.html', 'pwa-192x192.png', 'pwa-512x512.png'] as $file) {
            $this->assertFileExists(public_path($file));
        }

        $manifest = json_decode(file_get_contents(public_path('manifest.json')), true);
        $this->assertSame('Pan Motive', $manifest['name']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertCount(2, $manifest['icons']);

        $this->assertStringContainsString("self.addEventListener('push'", file_get_contents(public_path('sw.js')));
    }

    public function test_home_carries_the_motion_hooks_and_manifest_link(): void
    {
        $this->product();

        $this->get('/')
            ->assertOk()
            ->assertSee('data-counter-to', false)
            ->assertSee('data-reveal-group', false)
            ->assertSee('<link rel="manifest"', false);
    }

    public function test_add_to_cart_button_adds_via_livewire(): void
    {
        $product = $this->product();
        $buyer = User::factory()->create();

        Livewire::actingAs($buyer)
            ->test(AddToCartButton::class, ['product' => $product])
            ->call('add')
            ->assertSet('added', true)
            ->assertDispatched('cart-updated');

        $this->actingAs($buyer);
        $this->assertSame(1, app(CartService::class)->itemCount());
    }

    public function test_item_count_does_not_create_a_cart_row_for_a_fresh_guest(): void
    {
        app(CartService::class)->itemCount();
        $this->assertDatabaseCount('carts', 0);
    }

    public function test_vehicle_selector_resolves_to_a_filtered_shop_url(): void
    {
        $make = VehicleMake::create(['name' => 'Toyota', 'slug' => 'toyota-'.Str::random(4)]);
        $model = VehicleModel::create(['vehicle_make_id' => $make->id, 'name' => 'Camry', 'slug' => 'camry-'.Str::random(4)]);
        $vehicle = Vehicle::create(['vehicle_make_id' => $make->id, 'vehicle_model_id' => $model->id, 'year' => 2021]);

        Livewire::test(VehicleSelector::class)
            ->set('makeId', $make->id)
            ->set('modelId', $model->id)
            ->set('year', 2021)
            ->call('findParts')
            ->assertRedirect(route('products.index', ['vehicleId' => $vehicle->id]));
    }

    public function test_product_grid_ships_loading_skeletons(): void
    {
        $this->product();

        // Skeleton state is always in the DOM (toggled by wire:loading), and the
        // stagger group renders once results are present.
        $html = $this->get(route('products.index'))->assertOk()->getContent();

        $this->assertStringContainsString('animate-pulse', $html);
        $this->assertStringContainsString('wire:loading', $html);
    }

    public function test_focus_visible_styles_are_in_the_built_stylesheet(): void
    {
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
        $cssFile = collect($manifest)->firstWhere('src', 'resources/css/app.css')['file'] ?? null;

        $this->assertNotNull($cssFile, 'built app.css not found in manifest');
        $this->assertStringContainsString('focus-visible', file_get_contents(public_path('build/'.$cssFile)));
    }
}
