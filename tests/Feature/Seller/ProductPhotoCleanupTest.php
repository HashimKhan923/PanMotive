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
        for ($i = 1; $i <= $count; $i++) {
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

    public function test_photos_run_on_their_own_queue_and_are_counted_for_the_upload_page(): void
    {
        $p = $this->productWithPhotos(0);
        $tracker = 'bulk-import:'.$this->seller->id.':run-9';
        $gd = imagecreatetruecolor(300, 300);
        imagefill($gd, 0, 0, imagecolorallocate($gd, 229, 27, 43));
        ob_start();
        imagejpeg($gd);
        $jpeg = (string) ob_get_clean();
        imagedestroy($gd);
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg'])]);

        $job = new \App\Jobs\FetchProductImages($p->id, ['https://cdn.example.com/a.jpg', 'https://cdn.example.com/b.jpg'], [], $tracker);
        $this->assertSame('photos', $job->queue);

        \App\Jobs\FetchProductImages::countPlanned($tracker, 2);
        $job->handle();

        $this->assertSame(2, $p->fresh()->getMedia('images')->count());
        $this->assertSame(2, (int) \Illuminate\Support\Facades\Cache::get("{$tracker}:photos_total"));
        $this->assertSame(2, (int) \Illuminate\Support\Facades\Cache::get("{$tracker}:photos_done"));
    }

    public function test_a_listing_deleted_before_its_photos_download_leaves_nothing_in_storage(): void
    {
        $p = $this->productWithPhotos(0);
        $tracker = 'bulk-import:'.$this->seller->id.':run-10';
        \App\Jobs\FetchProductImages::countPlanned($tracker, 3);
        $p->delete();

        (new \App\Jobs\FetchProductImages($p->id, ['https://x/1.jpg', 'https://x/2.jpg', 'https://x/3.jpg'], [], $tracker))->handle();

        $this->assertSame([], $this->filesOnDisk());
        $this->assertSame(3, (int) \Illuminate\Support\Facades\Cache::get("{$tracker}:photos_done"), 'skipped photos still count, so the bar finishes');
    }

    public function test_upload_page_shows_photo_progress_after_the_products_are_done(): void
    {
        $id = 'run-11';
        $key = \App\Jobs\ProcessBulkProductUpload::progressKey($this->seller->id, $id);
        \Illuminate\Support\Facades\Cache::put($key, ['status' => 'done', 'result' => ['format' => 'shopify', 'created' => 5, 'skipped' => 0, 'errors' => []]]);
        \Illuminate\Support\Facades\Cache::put("{$key}:photos_total", 40);
        \Illuminate\Support\Facades\Cache::put("{$key}:photos_done", 12);

        Livewire::actingAs($this->withTwoFactor($this->seller))->test(\App\Livewire\Seller\BulkUpload::class)
            ->set('importId', $id)->call('refreshProgress')
            ->assertSee('Photos: 12 of 40 saved')
            ->assertSeeHtml('wire:poll.3s="refreshProgress"');
        $this->assertSame($id, session('bulk-import-id'), 'coming back to the page still shows the photo bar');

        \Illuminate\Support\Facades\Cache::put("{$key}:photos_done", 40);
        Livewire::actingAs($this->seller)->test(\App\Livewire\Seller\BulkUpload::class)
            ->assertSee('All 40 photos saved')->assertDontSeeHtml('wire:poll.3s');
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
