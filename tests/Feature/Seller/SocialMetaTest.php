<?php

namespace Tests\Feature\Seller;

use App\Http\Controllers\Seller\SocialConnectController;
use App\Livewire\Seller\SocialStudio;
use App\Models\SellerProfile;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Marketing\Social\MetaClient;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Sellers connect their own Facebook Page + linked Instagram and posts go out for real. */
class SocialMetaTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config()->set('services.meta', ['app_id' => 'app123', 'app_secret' => 'shh', 'config_id' => null, 'graph_version' => 'v27.0']);
        $this->seller = User::factory()->create();
        $this->seller->assignRole('seller');
        SellerProfile::create(['user_id' => $this->seller->id, 'business_name' => 'DK Motion', 'business_type' => 'llc', 'status' => 'approved', 'slug' => 'dk-motion']);
        $this->seller = $this->withTwoFactor($this->seller);
    }

    private function page(string $id, string $name, ?string $ig = null): array
    {
        return ['id' => $id, 'name' => $name, 'access_token' => 'page-token-'.$id, 'picture' => ['data' => ['url' => 'https://pic.test/'.$id]]]
            + ($ig ? ['instagram_business_account' => ['id' => 'ig'.$id, 'username' => $ig]] : []);
    }

    private function fakeLogin(array $pages): void
    {
        Http::fake([
            'graph.facebook.com/v27.0/oauth/access_token*' => fn (Request $r) => Http::response(['access_token' => isset($r->data()['fb_exchange_token']) ? 'long-user' : 'short-user']),
            'graph.facebook.com/v27.0/me/accounts*' => Http::response(['data' => $pages]),
        ]);
    }

    private function login(): \Illuminate\Testing\TestResponse
    {
        $res = $this->actingAs($this->seller)->get(route('seller.social.connect', 'facebook'));
        $this->assertStringStartsWith('https://www.facebook.com/v27.0/dialog/oauth?', $res->headers->get('Location'));
        $this->assertStringContainsString('instagram_content_publish', urldecode($res->headers->get('Location')));

        return $this->actingAs($this->seller)->get(route('seller.social.callback', ['platform' => 'facebook', 'code' => 'abc', 'state' => session('social_oauth.meta')]));
    }

    public function test_only_facebook_and_instagram_are_active_the_rest_are_coming_soon(): void
    {
        $this->actingAs($this->seller)->get(route('seller.social.connect', 'tiktok'))->assertSessionHas('error');

        Livewire::actingAs($this->seller)->test(SocialStudio::class)
            ->set('channels', ['facebook', 'tiktok'])->set('body', 'Hi')->call('publish')
            ->assertHasErrors('channels.1');

        Livewire::actingAs($this->seller)->test(SocialStudio::class)->set('tab', 'accounts')
            ->assertSee('Connect Facebook & Instagram')->assertSee('Coming soon')->assertSee('Before you connect');
    }

    public function test_one_login_connects_the_page_and_its_instagram(): void
    {
        $this->fakeLogin([$this->page('111', 'DK Motion', 'dkmotion')]);

        $this->login()->assertSessionHas('success', fn ($m) => str_contains($m, 'DK Motion') && str_contains($m, '@dkmotion'));

        $fb = SocialAccount::where('platform', 'facebook')->firstOrFail();
        $ig = SocialAccount::where('platform', 'instagram')->firstOrFail();
        $this->assertSame(['DK Motion', '111', 'page-token-111'], [$fb->handle, $fb->external_id, $fb->access_token]);
        $this->assertSame(['@dkmotion', 'ig111'], [$ig->handle, $ig->meta['ig_user_id']]);
        $this->assertTrue($fb->isLive() && $ig->isLive());
        // tokens are stored encrypted
        $this->assertStringNotContainsString('page-token-111', (string) DB::table('social_accounts')->where('id', $fb->id)->value('access_token'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'fb_exchange_token=short-user'));
    }

    public function test_a_seller_with_several_pages_chooses_one(): void
    {
        $this->fakeLogin([$this->page('111', 'My Personal Blog'), $this->page('222', 'DK Motion', 'dkmotion')]);

        $this->login()->assertSessionHas('success');
        $this->assertDatabaseCount('social_accounts', 0);

        Livewire::actingAs($this->seller)->test(SocialStudio::class)->set('tab', 'accounts')
            ->assertSee('Which Facebook Page should your posts go to?')->assertSee('My Personal Blog')->assertDontSee('page-token-222');

        $this->actingAs($this->seller)->post(route('seller.social.page'), ['page' => '222'])->assertSessionHas('success');
        $this->assertSame('222', SocialAccount::where('platform', 'facebook')->value('external_id'));
        $this->assertSame('ig222', SocialAccount::where('platform', 'instagram')->value('external_id'));

        // the choice can't be reused or used by someone else
        $this->actingAs($this->seller)->post(route('seller.social.page'), ['page' => '111'])->assertSessionHas('error');
    }

    public function test_posts_go_to_the_sellers_own_page_and_instagram(): void
    {
        $page = ['id' => '111', 'name' => 'DK Motion', 'token' => 'page-token', 'picture' => null, 'ig_id' => 'ig111', 'ig_username' => 'dkmotion'];
        app(MetaClient::class)->connectPage($this->seller->id, $page);
        Http::fake([
            'graph.facebook.com/v27.0/111/photos' => Http::response(['id' => 'photo1', 'post_id' => '111_999']),
            'graph.facebook.com/v27.0/ig111/media' => Http::response(['id' => 'container1']),
            'graph.facebook.com/v27.0/ig111/media_publish' => Http::response(['id' => 'media1']),
            'graph.facebook.com/v27.0/media1*' => Http::response(['permalink' => 'https://www.instagram.com/p/abc/']),
        ]);

        Livewire::actingAs($this->seller)->test(SocialStudio::class)
            ->set('channels', ['facebook', 'instagram'])->set('body', 'New LED reflectors in stock')
            ->set('media', UploadedFile::fake()->image('story.png', 1080, 1920))
            ->call('publish')->assertHasNoErrors();

        $post = SocialPost::with('targets.promoLink')->firstOrFail();
        $fb = $post->targets->firstWhere('platform', 'facebook');
        $ig = $post->targets->firstWhere('platform', 'instagram');
        $this->assertSame(['published', 'https://www.facebook.com/111_999'], [$fb->status, $fb->permalink]);
        $this->assertSame(['published', 'https://www.instagram.com/p/abc/'], [$ig->status, $ig->permalink]);

        // Facebook gets the tracked link; Instagram says "link in bio" (caption links aren't clickable there)
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '111/photos') && str_contains($r->body(), 'page-token') && str_contains($r->body(), route('promo.go', $fb->promoLink)));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), 'ig111/media') && str_contains($r['caption'], 'link in bio')
            && ! str_contains($r['caption'], 'http') && str_ends_with($r['image_url'], '.jpg'));
    }

    public function test_instagram_images_become_jpeg_within_its_shape_limits(): void
    {
        foreach ([[1080, 1920], [1000, 1500], [2400, 600], [1080, 1080]] as [$w, $h]) {
            $file = UploadedFile::fake()->image("i{$w}.png", $w, $h); // keep the temp file alive
            $src = $file->getPathname();
            $path = app(MetaClient::class)->instagramJpeg($src, $this->seller->id);
            [$ow, $oh, $type] = getimagesize(Storage::disk('public')->path($path));
            $this->assertSame(IMAGETYPE_JPEG, $type);
            $this->assertGreaterThanOrEqual(0.8, round($ow / $oh, 3), "{$w}x{$h}");
            $this->assertLessThanOrEqual(1.91, round($ow / $oh, 3), "{$w}x{$h}");
            $this->assertLessThanOrEqual(1440, $ow);
        }
    }

    public function test_an_expired_connection_fails_clearly_and_asks_to_reconnect(): void
    {
        app(MetaClient::class)->connectPage($this->seller->id, ['id' => '111', 'name' => 'DK Motion', 'token' => 'old', 'picture' => null, 'ig_id' => null, 'ig_username' => null]);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 190, 'message' => 'Session has expired']], 400)]);

        Livewire::actingAs($this->seller)->test(SocialStudio::class)
            ->set('channels', ['facebook'])->set('body', 'Text only post')->call('publish');

        $t = SocialPost::firstOrFail()->targets()->first();
        $this->assertSame('failed', $t->status);
        $this->assertStringContainsString('Reconnect', $t->error);
        $this->assertSame('expired', SocialAccount::where('platform', 'facebook')->value('status'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '111/feed'));

        // Retry after the account expired falls back to the share kit — never a fake post
        Livewire::actingAs($this->seller)->test(SocialStudio::class)->call('retry', $t->social_post_id);
        $this->assertSame('share_kit', $t->fresh()->status);
    }

    public function test_without_the_meta_app_sellers_see_coming_soon_and_the_data_deletion_page_works(): void
    {
        config()->set('services.meta.app_id', null);
        $this->actingAs($this->seller)->get(route('seller.social.connect', 'facebook'))->assertSessionHas('error');
        Livewire::actingAs($this->seller)->test(SocialStudio::class)->set('tab', 'accounts')->assertSee('Coming very soon');

        $this->get(route('data-deletion'))->assertOk()->assertSee('Delete your data');
        $this->assertSame([], SocialConnectController::pendingPages());
    }
}
