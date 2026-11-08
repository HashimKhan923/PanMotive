<?php

namespace Tests\Feature\Admin;

use App\Models\Banner;
use App\Models\User;
use Database\Seeders\AdminPermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class HomepageBannersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(AdminPermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake('public');
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('super_admin');

        return $this->withTwoFactor($u);
    }

    /** The homepage as a shopper sees it, not the signed-in admin. */
    private function asVisitor(): static
    {
        \Illuminate\Support\Facades\Auth::logout();

        return $this;
    }

    private function fields(array $overrides = []): array
    {
        return $overrides + [
            'eyebrow' => 'Winter sale',
            'title' => 'Brake parts, up to 30% off',
            'subtitle' => 'Pads, rotors and calipers.',
            'button_label' => 'Shop brakes',
            'button_url' => '/shop?category=brakes',
            'look' => 'charcoal',
            'placement' => 'below_categories',
            'sort_order' => 1,
            'is_active' => '1',
        ];
    }

    public function test_admin_creates_a_banner_with_a_photo_and_it_shows_on_the_homepage(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.banners.index'))->assertOk()->assertSee('No banners yet');
        $this->actingAs($admin)->get(route('admin.banners.create'))->assertOk();

        $this->actingAs($admin)->post(route('admin.banners.store'), $this->fields([
            'image' => UploadedFile::fake()->image('brakes.jpg', 1600, 900),
        ]))->assertRedirect();

        $banner = Banner::sole();
        $this->assertSame('Brake parts, up to 30% off', $banner->title);
        Storage::disk('public')->assertExists($banner->image_path);
        $this->assertDatabaseHas('activity_log', ['description' => 'Created homepage banner']);

        $this->actingAs($admin)->get(route('admin.banners.edit', $banner))->assertOk()->assertSee('Preview');

        $this->asVisitor()->get(rtrim(config('app.url'), '/').'/')->assertOk()
            ->assertSee('data-banner-rail', false)
            ->assertSee('Brake parts, up to 30% off')
            ->assertSee('href="/shop?category=brakes"', false)
            ->assertSee(basename($banner->image_path), false);
    }

    public function test_only_live_banners_show_and_each_in_its_own_place_in_order(): void
    {
        Banner::create(['title' => 'Switched off', 'is_active' => false]);
        Banner::create(['title' => 'Starts tomorrow', 'is_active' => true, 'starts_at' => now()->addDay()]);
        Banner::create(['title' => 'Already ended', 'is_active' => true, 'ends_at' => now()->subMinute()]);
        Banner::create(['title' => 'Second in deals spot', 'is_active' => true, 'placement' => 'below_deals', 'sort_order' => 2]);
        Banner::create(['title' => 'First in deals spot', 'is_active' => true, 'placement' => 'below_deals', 'sort_order' => 1, 'ends_at' => now()->addDay()]);
        Banner::create(['title' => 'Bottom one', 'is_active' => true, 'placement' => 'below_listings', 'style' => 'red']);

        $html = $this->get('/')->assertOk()
            ->assertDontSee('Switched off')->assertDontSee('Starts tomorrow')->assertDontSee('Already ended')
            ->assertSeeInOrder(['Shop by category', 'First in deals spot', 'Second in deals spot', 'New listings', 'Bottom one'])
            ->getContent();

        // Two in one place play as a slider with controls; one alone has none.
        $this->assertSame(1, substr_count($html, 'aria-roledescription="carousel"'));
        $this->assertStringContainsString('aria-label="Next banner"', $html);
    }

    public function test_the_homepage_is_unchanged_with_no_banners(): void
    {
        $this->get('/')->assertOk()->assertDontSee('data-banner-rail', false);
    }

    public function test_unsafe_links_and_bad_input_are_refused(): void
    {
        $admin = $this->admin();

        foreach (['javascript:alert(1)', '//evil.example/x', 'data:text/html,hi', 'ftp://files.example/x', 'shop/brakes'] as $bad) {
            $this->actingAs($admin)->post(route('admin.banners.store'), $this->fields(['button_url' => $bad]))
                ->assertSessionHasErrors('button_url');
        }

        $this->actingAs($admin)->post(route('admin.banners.store'), $this->fields(['button_url' => '']))
            ->assertSessionHasErrors('button_url'); // a button needs a link
        $this->actingAs($admin)->post(route('admin.banners.store'), $this->fields(['look' => 'neon']))->assertSessionHasErrors('look');
        $this->actingAs($admin)->post(route('admin.banners.store'), $this->fields(['placement' => 'footer']))->assertSessionHasErrors('placement');
        $this->actingAs($admin)->post(route('admin.banners.store'), $this->fields(['starts_at' => '2026-12-10 10:00', 'ends_at' => '2026-12-01 10:00']))
            ->assertSessionHasErrors('ends_at');
        $this->actingAs($admin)->post(route('admin.banners.store'), $this->fields(['image' => UploadedFile::fake()->image('tiny.jpg', 200, 100)]))
            ->assertSessionHasErrors('image');
        $this->actingAs($admin)->post(route('admin.banners.store'), $this->fields(['image' => UploadedFile::fake()->create('virus.svg', 10, 'image/svg+xml')]))
            ->assertSessionHasErrors('image');

        $this->assertSame(0, Banner::count());

        $this->actingAs($admin)->post(route('admin.banners.store'), $this->fields(['button_url' => 'https://www.example.com/sale']))->assertSessionHasNoErrors();
        $this->assertStringContainsString('rel=noopener', $this->asVisitor()->get(rtrim(config('app.url'), '/').'/')->getContent());
    }

    public function test_the_headline_is_escaped(): void
    {
        Banner::create(['title' => '<script>alert(1)</script>', 'is_active' => true]);

        $this->get('/')->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }

    public function test_replacing_or_removing_a_photo_deletes_the_old_file_and_delete_cleans_up(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.banners.store'), $this->fields(['image' => UploadedFile::fake()->image('a.jpg', 1600, 900)]));
        $banner = Banner::sole();
        $first = $banner->image_path;

        $this->actingAs($admin)->patch(route('admin.banners.update', $banner), $this->fields(['image' => UploadedFile::fake()->image('b.jpg', 1600, 900)]))->assertSessionHasNoErrors();
        $second = $banner->refresh()->image_path;
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);

        $this->actingAs($admin)->patch(route('admin.banners.update', $banner), $this->fields(['remove_image' => '1']))->assertSessionHasNoErrors();
        $this->assertNull($banner->refresh()->image_path);
        Storage::disk('public')->assertMissing($second);

        $this->actingAs($admin)->post(route('admin.banners.store'), $this->fields(['image' => UploadedFile::fake()->image('c.jpg', 1600, 900)]));
        $other = Banner::latest('id')->first();
        $this->actingAs($admin)->delete(route('admin.banners.destroy', $other))->assertRedirect(route('admin.banners.index'));
        $this->assertModelMissing($other);
        Storage::disk('public')->assertMissing($other->image_path);
    }

    public function test_unchecked_switch_turns_a_banner_off(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.banners.store'), $this->fields());
        $banner = Banner::sole();

        $fields = $this->fields();
        unset($fields['is_active']);
        $this->actingAs($admin)->patch(route('admin.banners.update', $banner), $fields);

        $this->assertFalse($banner->refresh()->is_active);
        $this->assertSame('off', $banner->state());
        $this->asVisitor()->get(rtrim(config('app.url'), '/').'/')->assertDontSee('Brake parts, up to 30% off');
    }

    public function test_people_without_content_access_cannot_manage_banners(): void
    {
        $banner = Banner::create(['title' => 'Keep me', 'is_active' => true]);
        $buyer = $this->withTwoFactor(User::factory()->create());

        $this->actingAs($buyer)->get(route('admin.banners.index'))->assertForbidden();
        $this->actingAs($buyer)->post(route('admin.banners.store'), $this->fields())->assertForbidden();
        $this->actingAs($buyer)->delete(route('admin.banners.destroy', $banner))->assertForbidden();
        $this->assertModelExists($banner);
    }
}
