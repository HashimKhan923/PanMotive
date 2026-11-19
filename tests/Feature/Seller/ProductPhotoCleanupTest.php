<?php

namespace Tests\Feature\Seller;

use App\Livewire\Seller\ProductForm;
use App\Livewire\Seller\ProductList;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Deleting a listing or a photo takes the files off the media disk (R2 in production) too. */
class ProductPhotoCleanupTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('public-local'); // the sweep also checks this disk — never let a test touch real files
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seller = User::factory()->create();
        $this->seller->assignRole('seller');
        SellerProfile::create(['user_id' => $this->seller->id, 'business_name' => 'Parts '.uniqid(), 'business_type' => 'llc', 'status' => 'approved', 'slug' => 'p-'.uniqid()]);
    }

    private function productWithPhotos(int $count = 2): Product
    {
        $cat = Category::create(['name' => 'Brake Pads', 'slug' => 'brake-pads-'.uniqid()]);
        $p = Product::create([
            'seller_id' => $this->seller->id, 'category_id' => $cat->id, 'title' => 'Pads '.uniqid(),
            'slug' => 'pads-'.uniqid(), 'price' => 20, 'quantity' => 3, 'status' => 'draft', 'condition' => 'new',
        ]);
        foreach (range(1, $count) as $i) {
            $p->addMedia(UploadedFile::fake()->image("p{$i}.jpg", 400, 400))->toMediaCollection('images');
        }

        return $p->fresh();
    }

    private function filesOnDisk(): array
    {
        return Storage::disk('public')->allFiles();
    }

    public function test_deleting_a_listing_removes_its_photos_from_storage(): void
    {
        $p = $this->productWithPhotos();
        $other = $this->productWithPhotos(1);
        $this->assertNotEmpty($this->filesOnDisk());

        Livewire::actingAs($this->withTwoFactor($this->seller))->test(ProductList::class)->call('delete', $p->id);

        $this->assertSoftDeleted($p);
        $this->assertSame(0, $p->media()->count());
        foreach ($this->filesOnDisk() as $f) {
            $this->assertStringStartsWith($other->getFirstMedia('images')->id.'/', $f, 'only the other listing\'s photo is left');
        }
        $this->assertNotEmpty($this->filesOnDisk());
    }

    public function test_bulk_delete_removes_every_listings_photos(): void
    {
        $a = $this->productWithPhotos();
        $b = $this->productWithPhotos();

        Livewire::actingAs($this->withTwoFactor($this->seller))->test(ProductList::class)
            ->set('selected', [(string) $a->id, (string) $b->id])->call('bulk', 'delete');

        $this->assertSame([], $this->filesOnDisk());
    }

    public function test_removing_one_photo_in_the_editor_deletes_its_files(): void
    {
        $p = $this->productWithPhotos();
        [$first, $second] = $p->getMedia('images')->all();

        Livewire::actingAs($this->withTwoFactor($this->seller))->test(ProductForm::class, ['product' => $p])
            ->call('removeImage', $first->id);

        foreach ($this->filesOnDisk() as $f) {
            $this->assertStringStartsWith($second->id.'/', $f);
        }
        $this->assertNotEmpty($this->filesOnDisk());
    }

    public function test_weekly_sweep_removes_only_unused_photo_folders(): void
    {
        $p = $this->productWithPhotos(1);
        $keep = $p->getFirstMedia('images')->id;
        Storage::disk('public')->put('99999/stray.webp', 'x');
        Storage::disk('public')->put('99999/conversions/stray-thumb.webp', 'x');
        Storage::disk('public')->put('banners/home.jpg', 'x');

        $this->artisan('media:sweep-orphans')->assertSuccessful();

        $this->assertFalse(Storage::disk('public')->exists('99999/stray.webp'));
        $this->assertTrue(Storage::disk('public')->exists('banners/home.jpg'), 'named folders are never touched');
        $this->assertNotEmpty(Storage::disk('public')->files((string) $keep), 'a photo still in use stays');
    }
}
