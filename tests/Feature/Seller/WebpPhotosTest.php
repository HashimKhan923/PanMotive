<?php

namespace Tests\Feature\Seller;

use App\Jobs\FetchProductImages;
use App\Livewire\Seller\ProductForm;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Support\WebpImage;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class WebpPhotosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake('public');
    }

    private function seller(): User
    {
        $u = User::factory()->create();
        $u->assignRole('seller');
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'Acme '.uniqid(), 'business_type' => 'llc', 'status' => 'approved']);

        return $this->withTwoFactor($u);
    }

    private function imageFile(string $type, int $w = 300, int $h = 200): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 229, 27, 43));
        $path = tempnam(sys_get_temp_dir(), 'pm-test-').'.'.$type;
        match ($type) {
            'jpg' => imagejpeg($img, $path),
            'png' => imagepng($img, $path),
            'gif' => imagegif($img, $path),
        };
        imagedestroy($img);

        return $path;
    }

    private function mime(string $path): string
    {
        return (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
    }

    public function test_jpg_png_and_gif_photos_become_webp(): void
    {
        foreach (['jpg', 'png', 'gif'] as $type) {
            $webp = WebpImage::fromFile($this->imageFile($type));

            $this->assertSame('image/webp', $this->mime($webp), "$type should become WebP");
            $this->assertSame([300, 200], array_slice(getimagesize($webp), 0, 2));
            @unlink($webp);
        }
    }

    public function test_very_large_photos_are_shrunk_to_the_maximum_size(): void
    {
        $webp = WebpImage::fromFile($this->imageFile('png', 4000, 1000));

        [$w, $h] = getimagesize($webp);
        $this->assertSame(WebpImage::MAX_SIDE, $w);
        $this->assertSame(500, $h);
    }

    public function test_files_that_are_not_photos_are_refused(): void
    {
        $fake = tempnam(sys_get_temp_dir(), 'pm-test-').'.jpg';
        file_put_contents($fake, '<?php echo "not a photo";');
        $svg = tempnam(sys_get_temp_dir(), 'pm-test-').'.png';
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        foreach ([$fake, $svg] as $file) {
            try {
                WebpImage::fromFile($file);
                $this->fail('A non-photo was accepted: '.basename($file));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_file_names_end_in_webp(): void
    {
        $this->assertSame('brake-pads.webp', WebpImage::fileName('brake pads.JPG'));
        $this->assertSame('photo.webp', WebpImage::fileName('.png'));
        $this->assertSame('IMG_0042.webp', WebpImage::fileName('IMG_0042.HEIC'));
    }

    public function test_photos_uploaded_in_the_product_form_are_stored_as_webp(): void
    {
        $seller = $this->seller();
        $cat = Category::create(['name' => 'Brakes', 'slug' => 'brakes-'.uniqid()]);

        Livewire::actingAs($seller)->test(ProductForm::class)
            ->set('title', 'Ceramic Brake Pads')->set('category_id', $cat->id)
            ->set('price', 40)->set('quantity', 5)
            ->set('newImages', [UploadedFile::fake()->image('front pads.jpg', 640, 480), UploadedFile::fake()->image('box.png', 400, 400)])
            ->call('submit')
            ->assertHasNoErrors();

        $media = Product::where('title', 'Ceramic Brake Pads')->firstOrFail()->getMedia('images');
        $this->assertCount(2, $media);
        foreach ($media as $m) {
            $this->assertSame('image/webp', $m->mime_type);
            $this->assertStringEndsWith('.webp', $m->file_name);
            $this->assertSame('image/webp', $this->mime($m->getPath()));
        }
        $this->assertSame(['front-pads.webp', 'box.webp'], $media->pluck('file_name')->all());
    }

    public function test_the_product_form_refuses_files_that_are_not_photos(): void
    {
        $seller = $this->seller();

        $cat = Category::create(['name' => 'Brakes', 'slug' => 'brakes-'.uniqid()]);
        $pdf = UploadedFile::fake()->create('invoice.pdf', 20, 'application/pdf');

        // Shown as soon as the file is chosen…
        Livewire::actingAs($seller)->test(ProductForm::class)
            ->set('newImages', [$pdf])
            ->assertHasErrors(['newImages.0' => 'mimes']);

        // …and saving is blocked too.
        Livewire::actingAs($seller)->test(ProductForm::class)
            ->set('title', 'Rotor')->set('category_id', $cat->id)->set('price', 40)->set('quantity', 5)
            ->set('newImages', [$pdf])
            ->call('submit')
            ->assertHasErrors(['newImages.0' => 'mimes']);
        $this->assertSame(0, Product::where('title', 'Rotor')->count());
    }

    public function test_imported_photos_from_links_are_stored_as_webp(): void
    {
        $jpeg = (string) file_get_contents($this->imageFile('jpg', 500, 500));
        Http::fake(['*' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg'])]);
        $seller = $this->seller();
        $product = Product::create([
            'seller_id' => $seller->id, 'category_id' => Category::create(['name' => 'C', 'slug' => 'c-'.uniqid()])->id,
            'title' => 'Mirror', 'slug' => 'mirror-'.uniqid(), 'price' => 10, 'quantity' => 1, 'status' => 'draft',
        ]);

        FetchProductImages::dispatchSync($product->id, ['https://cdn.example.com/parts/mirror-left.jpg?v=2']);

        $m = $product->getFirstMedia('images');
        $this->assertNotNull($m);
        $this->assertSame('image/webp', $m->mime_type);
        $this->assertSame('mirror-left.webp', $m->file_name);
    }
}
