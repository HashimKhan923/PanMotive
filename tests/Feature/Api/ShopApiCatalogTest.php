<?php

namespace Tests\Feature\Api;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Question;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Auth\IdTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The shopping app's API (/api/shop/v1): signing in, browsing, vehicles, wishlist, stores, Q&A. */
class ShopApiCatalogTest extends TestCase
{
    use RefreshDatabase;

    private ?User $seller = null;

    private const DEVICE = 'test-device-0000-1111-2222';

    private function seller(): User
    {
        if ($this->seller) {
            return $this->seller;
        }
        $u = User::factory()->create();
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'Hirev Sports', 'business_type' => 'llc', 'status' => 'approved', 'slug' => 'hirev-sports']);

        return $this->seller = $u;
    }

    private function product(array $extra = []): Product
    {
        return Product::create(array_merge([
            'seller_id' => $this->seller()->id,
            'category_id' => Category::where('slug', 'other-parts')->value('id') ?? Category::create(['name' => 'Other Parts', 'slug' => 'other-parts'])->id,
            'title' => 'LED Tail Lights '.Str::random(4), 'slug' => 'p-'.Str::random(10),
            'price' => 100, 'quantity' => 5, 'status' => 'active', 'condition' => 'new',
        ], $extra))->fresh();
    }

    private function vehicle(string $make = 'Honda', string $model = 'Civic', int $year = 2019): Vehicle
    {
        $m = VehicleMake::firstOrCreate(['slug' => Str::slug($make)], ['name' => $make]);
        $mod = VehicleModel::firstOrCreate(['vehicle_make_id' => $m->id, 'slug' => Str::slug($model)], ['name' => $model]);

        return Vehicle::create(['vehicle_make_id' => $m->id, 'vehicle_model_id' => $mod->id, 'year' => $year]);
    }

    /** Each call starts clean: withHeaders() otherwise keeps headers from earlier requests in the test. */
    private function app_(array $headers = []): self
    {
        $this->flushHeaders();

        return $this->withHeaders(['Accept' => 'application/json', 'X-Device-Id' => self::DEVICE] + $headers);
    }

    public function test_register_login_me_logout_and_a_dead_token_is_refused(): void
    {
        $reg = $this->app_()->postJson('/api/shop/v1/auth/register', ['name' => 'Sam Driver', 'email' => 'sam@example.com', 'password' => 'Str0ng-Pass!42'])
            ->assertCreated()->assertJsonPath('user.email', 'sam@example.com');
        $token = $reg->json('token');
        $this->assertNotEmpty($token);

        $this->app_()->postJson('/api/shop/v1/auth/login', ['email' => 'sam@example.com', 'password' => 'wrong'])->assertStatus(422);
        $login = $this->app_()->postJson('/api/shop/v1/auth/login', ['email' => 'sam@example.com', 'password' => 'Str0ng-Pass!42'])->assertOk();

        $this->app_(['Authorization' => 'Bearer '.$login->json('token')])->getJson('/api/shop/v1/me')
            ->assertOk()->assertJsonPath('user.name', 'Sam Driver');

        $this->app_(['Authorization' => 'Bearer '.$login->json('token')])->postJson('/api/shop/v1/auth/logout')->assertOk();
        $this->app_(['Authorization' => 'Bearer '.$login->json('token')])->getJson('/api/shop/v1/me')->assertStatus(401);
        $this->app_(['Authorization' => 'Bearer nonsense'])->getJson('/api/shop/v1/home')->assertStatus(401);
        $this->app_()->getJson('/api/shop/v1/me')->assertStatus(401);
    }

    public function test_a_suspended_account_is_signed_out(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('phone')->plainTextToken;
        $user->forceFill(['is_active' => false])->save();

        $this->app_(['Authorization' => 'Bearer '.$token])->getJson('/api/shop/v1/home')->assertStatus(403)->assertJsonPath('code', 'suspended');
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_guest_wishlist_on_the_phone_moves_into_the_account_on_sign_in(): void
    {
        $p = $this->product();
        $this->app_()->postJson('/api/shop/v1/wishlist/toggle', ['product_id' => $p->id])->assertOk()->assertJsonPath('saved', true);
        $this->app_()->getJson('/api/shop/v1/wishlist/ids')->assertJsonPath('ids', [$p->id]);

        $user = User::factory()->create(['password' => bcrypt('Str0ng-Pass!42')]);
        $token = $this->app_()->postJson('/api/shop/v1/auth/login', ['email' => $user->email, 'password' => 'Str0ng-Pass!42'])->json('token');

        $this->flushHeaders();
        $this->withHeaders(['Accept' => 'application/json', 'X-Device-Id' => 'another-phone-123456', 'Authorization' => 'Bearer '.$token])
            ->getJson('/api/shop/v1/wishlist')->assertOk()->assertJsonPath('products.0.id', $p->id)->assertJsonPath('products.0.saved', true);
    }

    public function test_home_and_search_show_make_model_not_brand_and_fit_the_garage(): void
    {
        $civic = $this->vehicle();
        $brand = Brand::create(['name' => 'ZHENGWO', 'slug' => 'zhengwo']);
        $fits = $this->product(['title' => 'Civic Fog Lights', 'brand_id' => $brand->id, 'sale_price' => 80]);
        $fits->fitments()->attach($civic->id);
        $other = $this->product(['title' => 'Camry Fog Lights']);
        $other->fitments()->attach($this->vehicle('Toyota', 'Camry', 2020)->id);

        $home = $this->app_(['X-Vehicle-Id' => (string) $civic->id])->getJson('/api/shop/v1/home')->assertOk();
        $home->assertJsonPath('garage.id', $civic->id)->assertJsonPath('for_vehicle.0.id', $fits->id);
        $this->assertStringNotContainsString('ZHENGWO', $home->getContent());

        $res = $this->app_(['X-Vehicle-Id' => (string) $civic->id])->getJson('/api/shop/v1/products?q=fog')->assertOk();
        $cards = collect($res->json('products'))->keyBy('id');
        $this->assertSame('Honda Civic', $cards[$fits->id]['fits_vehicle']);
        $this->assertTrue($cards[$fits->id]['fits_garage']);
        $this->assertFalse($cards[$other->id]['fits_garage']);
        $this->assertSame(80.0, (float) $cards[$fits->id]['effective_price']);
        $this->assertSame(20, $cards[$fits->id]['off_pct']);
        $this->assertArrayHasKey('facets', $res->json());

        // Same filters as the website: only the Civic part when filtering by its vehicle.
        $ids = collect($this->app_()->getJson('/api/shop/v1/products?vehicleId='.$civic->id)->json('products'))->pluck('id')->all();
        $this->assertSame([$fits->id], $ids);
    }

    public function test_product_page_has_everything_the_app_shows(): void
    {
        $civic = $this->vehicle();
        $p = $this->product(['title' => 'Civic Headlights', 'description' => '<p><strong>Bright</strong> lights</p>', 'part_number' => 'HL-1']);
        $p->fitments()->attach($civic->id);

        $res = $this->app_()->getJson('/api/shop/v1/products/'.$p->slug)->assertOk();
        $res->assertJsonPath('product.id', $p->id)
            ->assertJsonPath('product.fits_vehicle', 'Honda Civic')
            ->assertJsonPath('product.fitments.0.make', 'Honda')
            ->assertJsonPath('product.seller.slug', 'hirev-sports')
            ->assertJsonPath('product.part_number', 'HL-1');
        $this->assertStringContainsString('<strong>Bright</strong>', $res->json('product.description_html'));

        $this->app_()->getJson('/api/shop/v1/products/'.$p->id.'/related')->assertOk()->assertJsonStructure(['frequently_bought', 'also_viewed', 'related']);
        $this->app_()->getJson('/api/shop/v1/products/by-ids?ids='.$p->id)->assertOk()->assertJsonPath('products.0.id', $p->id);

        $p->update(['status' => 'draft']);
        $this->app_()->getJson('/api/shop/v1/products/'.$p->slug)->assertNotFound();
    }

    public function test_vehicle_picker_resolve_and_garage_on_the_account(): void
    {
        $civic = $this->vehicle();
        $makeId = $civic->vehicle_make_id;
        $modelId = $civic->vehicle_model_id;

        $this->app_()->getJson('/api/shop/v1/vehicles/makes')->assertOk()->assertJsonFragment(['name' => 'Honda']);
        $this->app_()->getJson("/api/shop/v1/vehicles/makes/{$makeId}/models")->assertJsonPath('models.0.name', 'Civic');
        $this->app_()->getJson("/api/shop/v1/vehicles/models/{$modelId}/years")->assertJsonPath('years', [2019]);
        $this->app_()->getJson("/api/shop/v1/vehicles/resolve?model_id={$modelId}&year=2019")->assertJsonPath('vehicle.label', '2019 Honda Civic');

        $user = User::factory()->create();
        $token = $user->createToken('phone')->plainTextToken;
        $this->app_(['Authorization' => 'Bearer '.$token])->putJson('/api/shop/v1/garage', ['vehicle_id' => $civic->id])->assertOk();
        $this->assertSame($civic->id, $user->fresh()->active_vehicle['id']);
    }

    public function test_vin_scan_finds_the_vehicle(): void
    {
        $civic = $this->vehicle('Honda', 'Civic', 2019);
        Http::fake(['vpic.nhtsa.dot.gov/*' => Http::response(['Results' => [[
            'Make' => 'HONDA', 'Model' => 'Civic', 'ModelYear' => '2019', 'DisplacementL' => '2.0', 'EngineCylinders' => '4', 'Trim' => 'EX',
        ]]])]);

        $this->app_()->postJson('/api/shop/v1/vehicles/vin', ['vin' => '2HGFC2F59KH500000'])->assertOk()
            ->assertJsonPath('vehicle.id', $civic->id)->assertJsonPath('decoded.model', 'Civic');
        $this->app_()->postJson('/api/shop/v1/vehicles/vin', ['vin' => 'TOO-SHORT-123'])->assertStatus(422);
    }

    public function test_sign_in_with_apple_uses_the_verified_token_and_staff_are_refused(): void
    {
        $verifier = $this->mock(IdTokenVerifier::class);
        $verifier->shouldReceive('configured')->andReturn(true);
        $verifier->shouldReceive('verify')->andReturn(['sub' => 'apple-123', 'email' => 'pat@privaterelay.appleid.com', 'email_verified' => true, 'name' => null]);

        $res = $this->app_()->postJson('/api/shop/v1/auth/social/apple', ['id_token' => 'x.y.z', 'name' => 'Pat Lee'])->assertCreated();
        $this->assertSame('Pat Lee', $res->json('user.name'));
        $this->assertSame('apple-123', User::where('email', 'pat@privaterelay.appleid.com')->value('apple_id'));

        // Signing in again finds the same account by the Apple id, even without an email.
        $verifier2 = $this->mock(IdTokenVerifier::class);
        $verifier2->shouldReceive('configured')->andReturn(true);
        $verifier2->shouldReceive('verify')->andReturn(['sub' => 'apple-123', 'email' => null, 'email_verified' => false, 'name' => null]);
        $this->app_()->postJson('/api/shop/v1/auth/social/apple', ['id_token' => 'x.y.z'])->assertOk()->assertJsonPath('user.email', 'pat@privaterelay.appleid.com');

        $this->app_()->postJson('/api/shop/v1/auth/social/facebook', ['id_token' => 'x'])->assertNotFound();
    }

    public function test_delete_account_from_the_app(): void
    {
        $user = User::factory()->create(['password' => bcrypt('Str0ng-Pass!42')]);
        $token = $user->createToken('phone')->plainTextToken;

        $this->app_(['Authorization' => 'Bearer '.$token])->deleteJson('/api/shop/v1/me', ['password' => 'nope'])->assertStatus(422);
        $this->app_(['Authorization' => 'Bearer '.$token])->deleteJson('/api/shop/v1/me', ['password' => 'Str0ng-Pass!42'])->assertOk();
        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_store_follow_questions_and_reviews(): void
    {
        $p = $this->product();
        $user = User::factory()->create(['name' => 'Jordan Smith']);
        $token = $user->createToken('phone')->plainTextToken;
        $auth = ['Authorization' => 'Bearer '.$token];

        $this->app_()->getJson('/api/shop/v1/stores/hirev-sports')->assertOk()->assertJsonPath('store.name', 'Hirev Sports')->assertJsonPath('store.listings', 1);
        $this->app_()->getJson('/api/shop/v1/stores/hirev-sports/products')->assertOk()->assertJsonPath('products.0.id', $p->id);
        $this->app_($auth)->postJson('/api/shop/v1/stores/hirev-sports/follow')->assertOk()->assertJsonPath('following', true);

        $this->app_($auth)->postJson("/api/shop/v1/products/{$p->id}/questions", ['body' => 'Does this fit the hatchback?'])->assertCreated();
        $this->app_()->getJson("/api/shop/v1/products/{$p->id}/questions")->assertOk()->assertJsonPath('questions.0.author', 'Jordan S.');
        $this->app_()->postJson("/api/shop/v1/products/{$p->id}/questions", ['body' => 'Guests can not ask'])->assertStatus(401);

        // Only buyers can review.
        $this->app_($auth)->postJson("/api/shop/v1/products/{$p->id}/reviews", ['rating' => 5, 'body' => 'Great lights, easy fit.'])->assertStatus(403);
        $this->app_()->getJson("/api/shop/v1/products/{$p->id}/reviews")->assertOk()->assertJsonPath('can_review', false);
        $this->assertSame(1, Question::count());
    }

    public function test_suggestions_are_not_shared_between_shoppers(): void
    {
        $p = $this->product(['title' => 'Carbon Spoiler Wing']);
        $user = User::factory()->create();
        $token = $user->createToken('phone')->plainTextToken;

        $this->app_(['Authorization' => 'Bearer '.$token])->postJson('/api/shop/v1/wishlist/toggle', ['product_id' => $p->id]);
        $this->app_(['Authorization' => 'Bearer '.$token])->getJson('/api/shop/v1/search/suggest?q=spoiler')->assertJsonPath('products.0.saved', true);
        $this->flushHeaders();
        $this->withHeaders(['Accept' => 'application/json', 'X-Device-Id' => 'someone-else-12345678'])
            ->getJson('/api/shop/v1/search/suggest?q=spoiler')->assertJsonPath('products.0.saved', false);
    }

    public function test_maintenance_mode_answers_the_app_with_503(): void
    {
        config(['marketplace.platform.maintenance' => true]);
        $this->app_()->getJson('/api/shop/v1/home')->assertStatus(503)->assertJsonPath('code', 'maintenance');
    }
}
