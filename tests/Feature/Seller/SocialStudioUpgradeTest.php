<?php

namespace Tests\Feature\Seller;

use App\Livewire\Seller\SocialStudio;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\PromoLink;
use App\Models\SellerProfile;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Marketing\Social\SocialToolkit;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Social studio upgrade: captions per app, plan my week, calendar, auto-queue, link in bio, image proxy. */
class SocialStudioUpgradeTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seller = User::factory()->create();
        $this->seller->assignRole('seller');
        SellerProfile::create(['user_id' => $this->seller->id, 'business_name' => 'AutoPro Parts', 'business_type' => 'llc', 'status' => 'approved', 'slug' => 'autopro']);
        $this->seller = $this->withTwoFactor($this->seller);
    }

    private function product(bool $photo = true, array $attrs = []): Product
    {
        $p = Product::create(array_merge([
            'seller_id' => $this->seller->id, 'category_id' => Category::firstOrCreate(['slug' => 'brakes'], ['name' => 'Brakes'])->id,
            'title' => 'Brake Pads '.Str::random(3), 'slug' => 'bp-'.Str::random(8), 'price' => 60, 'quantity' => 5, 'status' => 'active',
        ], $attrs));
        if ($photo) {
            $p->addMedia(UploadedFile::fake()->image('pads.jpg', 600, 600))->toMediaCollection('images');
        }

        return $p->fresh();
    }

    public function test_each_app_can_get_its_own_caption_and_the_share_kit_uses_it(): void
    {
        $p = $this->product();

        Livewire::actingAs($this->seller)->test(SocialStudio::class)
            ->set('channels', ['facebook', 'x'])->set('productId', $p->id)
            ->call('generateAll')
            ->assertSet('captions.facebook', fn ($c) => is_string($c) && $c !== '')
            ->assertSet('captions.x', fn ($c) => mb_strlen($c) <= 280)
            ->set('body', 'Main caption')
            ->set('captions.x', 'Short one for X #brakes')
            ->call('publish');

        $post = SocialPost::with('targets')->firstOrFail();
        $this->assertSame('Short one for X #brakes', $post->targets->firstWhere('platform', 'x')->caption);
        $this->assertSame('share_kit', $post->targets->firstWhere('platform', 'x')->status);
    }

    public function test_plan_my_week_schedules_a_post_a_day_and_posts_can_be_moved(): void
    {
        foreach (range(1, 8) as $i) {
            $this->product();
        }
        $this->product(false); // no photo: never picked

        Livewire::actingAs($this->seller)->test(SocialStudio::class)
            ->set('channels', ['facebook', 'instagram'])->call('planWeek')->assertSet('tab', 'calendar');

        $posts = SocialPost::where('seller_id', $this->seller->id)->orderBy('scheduled_at')->get();
        $this->assertCount(7, $posts);
        $this->assertSame(7, $posts->pluck('scheduled_at')->map->toDateString()->unique()->count());
        $this->assertTrue($posts->every(fn ($p) => $p->media_path && $p->status === 'scheduled' && $p->targets()->count() === 2));

        $first = $posts->first();
        $newDay = now()->addDays(10)->format('Y-m-d');
        Livewire::actingAs($this->seller)->test(SocialStudio::class)->call('reschedule', $first->id, $newDay);
        $this->assertSame($newDay, $first->fresh()->scheduled_at->toDateString());
        $this->assertSame($first->scheduled_at->format('H:i'), $first->fresh()->scheduled_at->format('H:i'));

        Livewire::actingAs($this->seller)->test(SocialStudio::class, ['tab' => 'calendar'])->set('tab', 'calendar')->assertSee('Auto-queue')->assertSee('Drag a scheduled post');
    }

    public function test_auto_queue_lines_up_one_post_a_day_when_switched_on(): void
    {
        $this->product();
        Livewire::actingAs($this->seller)->test(SocialStudio::class)
            ->set('queue.enabled', true)->set('queue.hour', 18)->set('queue.channels', ['facebook'])->set('queue.days', [1, 2, 3, 4, 5, 6, 7])
            ->call('saveQueue');

        $toolkit = app(SocialToolkit::class);
        $this->assertSame(1, $toolkit->runQueue());
        $this->assertSame(0, $toolkit->runQueue()); // once a day
        $this->assertSame(1, SocialPost::where('seller_id', $this->seller->id)->count());
    }

    public function test_link_in_bio_page_shows_featured_parts_with_tracked_links(): void
    {
        $featured = $this->product(true, ['title' => 'Featured Rotor']);
        $this->product(true, ['title' => 'Sale Caliper', 'price' => 100, 'sale_price' => 80]);
        $coupon = Coupon::create(['code' => 'BIO10', 'type' => 'percentage', 'value' => 10, 'seller_id' => $this->seller->id, 'is_active' => true]);

        Livewire::actingAs($this->seller)->test(SocialStudio::class)
            ->set('bio.headline', 'Parts for your build')->set('bio.product_ids', [$featured->id])->set('bio.coupon_id', $coupon->id)
            ->call('saveBio');

        $page = $this->get(config('app.url').'/@autopro')->assertOk()
            ->assertSee('Parts for your build')->assertSee('Featured Rotor')->assertSee('BIO10')->assertSee('Shop all parts');
        $this->assertSame(1, $this->seller->sellerProfile->fresh()->bio_views);

        $link = PromoLink::where('seller_id', $this->seller->id)->where('product_id', $featured->id)->firstOrFail();
        $this->assertSame('bio', $link->channel());
        $this->get(route('promo.go', $link))->assertRedirect(route('products.show', $featured->slug));
        $this->assertSame(1, $link->fresh()->clicks);

        // Switched off: the page is gone.
        Livewire::actingAs($this->seller)->test(SocialStudio::class)->set('bio.enabled', false)->call('saveBio');
        $this->get(config('app.url').'/@autopro')->assertNotFound();
    }

    public function test_hashtags_best_time_and_image_proxy_only_for_own_products(): void
    {
        $p = $this->product();
        $tags = app(SocialToolkit::class)->hashtags($p);
        $this->assertContains('#brakes', $tags);
        $this->assertContains('#carparts', $tags);
        $this->assertSame(18, app(SocialToolkit::class)->bestHour($this->seller->id)['hour']);

        $this->actingAs($this->seller)->get(route('seller.social.image', $p))->assertOk();
        $other = User::factory()->create();
        $other->assignRole('seller');
        SellerProfile::create(['user_id' => $other->id, 'business_name' => 'Other', 'business_type' => 'llc', 'status' => 'approved', 'slug' => 'other']);
        $this->actingAs($this->withTwoFactor($other))->get(route('seller.social.image', $p))->assertNotFound();

        Livewire::actingAs($this->seller)->test(SocialStudio::class)
            ->assertSee('Image maker')->assertSee('How it looks')->set('productId', $p->id)->assertSee('#brakes');
    }
}
