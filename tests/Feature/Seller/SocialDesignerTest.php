<?php

namespace Tests\Feature\Seller;

use App\Livewire\Seller\SocialDesigner;
use App\Livewire\Seller\SocialStudio;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\SocialDesign;
use App\Models\SocialPost;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Free-hand social designer and saved designs. */
class SocialDesignerTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seller = User::factory()->create();
        $this->seller->assignRole('seller');
        SellerProfile::create(['user_id' => $this->seller->id, 'business_name' => 'AutoPro Parts', 'business_type' => 'llc', 'status' => 'approved', 'slug' => 'autopro']);
        $this->seller = $this->withTwoFactor($this->seller);
    }

    private function product(): Product
    {
        $p = Product::create([
            'seller_id' => $this->seller->id, 'category_id' => Category::firstOrCreate(['slug' => 'brakes'], ['name' => 'Brakes'])->id,
            'title' => 'Brake Pads '.Str::random(3), 'slug' => 'bp-'.Str::random(8), 'price' => 60, 'quantity' => 5, 'status' => 'active',
        ]);
        $p->addMedia(UploadedFile::fake()->image('pads.jpg', 600, 600))->toMediaCollection('images');

        return $p->fresh();
    }

    public function test_the_designer_page_opens(): void
    {
        $this->product();
        $this->actingAs($this->seller)->get(route('seller.social.design'))->assertOk()->assertSee('pmSocialDesigner', false);
    }

    public function test_a_design_is_saved_and_can_be_used_in_a_post(): void
    {
        $canvas = json_encode(['version' => '6.9.1', 'objects' => [['type' => 'Textbox', 'text' => 'Hello']]]);

        Livewire::actingAs($this->seller)->test(SocialDesigner::class)
            ->set('name', 'Brake sale')
            ->set('png', UploadedFile::fake()->image('design.png', 1080, 1080))
            ->call('save', $canvas, 'square', false)
            ->assertHasNoErrors();

        $d = SocialDesign::firstOrFail();
        $this->assertSame('Brake sale', $d->name);
        $this->assertSame($this->seller->id, $d->seller_id);
        Storage::disk('public')->assertExists($d->image_path);

        // "Use in a post" sends the seller to the composer with the design attached
        Livewire::actingAs($this->seller)->test(SocialDesigner::class, ['design' => $d->id])
            ->set('png', UploadedFile::fake()->image('design.png', 1080, 1080))
            ->call('save', $canvas, 'square', true)
            ->assertRedirect(route('seller.social.index', ['tab' => 'compose', 'design' => $d->id]));

        Livewire::actingAs($this->seller)->test(SocialStudio::class)
            ->set('design', $d->id)->set('channels', ['facebook'])->set('body', 'Check this out')
            ->call('publish');
        $this->assertSame($d->fresh()->image_path, SocialPost::firstOrFail()->media_path);
    }

    public function test_bad_canvas_data_and_other_sellers_designs_are_refused(): void
    {
        $other = User::factory()->create();
        $theirs = SocialDesign::create(['seller_id' => $other->id, 'name' => 'Theirs', 'size' => 'square', 'canvas' => '{}', 'image_path' => 'x.png']);

        Livewire::actingAs($this->seller)->test(SocialDesigner::class)
            ->set('png', UploadedFile::fake()->image('d.png', 100, 100))
            ->call('save', 'not json', 'square', false)
            ->assertStatus(422);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::actingAs($this->seller)->test(SocialDesigner::class, ['design' => $theirs->id]);
    }

    public function test_uploaded_design_photos_are_only_served_to_their_owner(): void
    {
        $name = Str::random(30).'.png';
        Storage::disk('public')->put('social/'.$this->seller->id.'/uploads/'.$name, 'png-bytes');

        $this->actingAs($this->seller)->get(route('seller.social.upload', $name))->assertOk();

        $other = User::factory()->create();
        $other->assignRole('seller');
        SellerProfile::create(['user_id' => $other->id, 'business_name' => 'Other', 'business_type' => 'llc', 'status' => 'approved', 'slug' => 'other']);
        $other = $this->withTwoFactor($other);
        $this->actingAs($other)->get(route('seller.social.upload', $name))->assertNotFound();
        $this->actingAs($other)->get(route('seller.social.upload', '..%2F..%2Fsecret.png'))->assertNotFound();
    }
}
