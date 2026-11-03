<?php

namespace Tests\Feature\Seller;

use App\Livewire\Seller\SocialStudio;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Marketing\Social\SocialPublisher;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SocialStudioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seller(): User
    {
        $u = User::factory()->create();
        $u->assignRole('seller');
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'Social '.uniqid(), 'business_type' => 'llc', 'status' => 'approved']);

        return $this->withTwoFactor($u);
    }

    public function test_unconfigured_platform_gets_a_share_kit_never_a_fake_post(): void
    {
        $seller = $this->seller();

        // No platform app configured → no pretend "demo" connection.
        $this->actingAs($seller)->get(route('seller.social.connect', 'facebook'))
            ->assertRedirect(route('seller.social.index', ['tab' => 'accounts']));
        $this->assertDatabaseCount('social_accounts', 0);

        Livewire::actingAs($seller)->test(SocialStudio::class)
            ->set('channels', ['facebook', 'x'])
            ->set('body', 'Fresh brake kits in stock this week.')
            ->set('when', 'now')
            ->call('publish')
            ->assertHasNoErrors();

        $post = SocialPost::with('targets.promoLink')->firstOrFail();
        $this->assertSame('ready', $post->status);
        $this->assertCount(2, $post->targets);
        $this->assertTrue($post->targets->every(fn ($t) => $t->status === 'share_kit' && ! $t->external_id && ! $t->permalink && $t->promoLink));

        // Each channel's tracked link counts real clicks as "social" in insights.
        $this->get(route('promo.go', $post->targets[0]->promoLink));
        $this->assertSame(1, $post->targets[0]->promoLink->fresh()->clicks);
        $this->assertDatabaseHas('marketing_events', ['seller_id' => $seller->id, 'channel' => 'social', 'kind' => 'click']);

        // The seller confirms they shared it.
        Livewire::actingAs($seller)->test(SocialStudio::class)->call('markShared', $post->targets[0]->id);
        $this->assertSame('shared', $post->targets[0]->fresh()->status);
    }

    public function test_configured_platform_with_real_connection_auto_posts(): void
    {
        config()->set('services.social.facebook', [
            'client_id' => 'cid', 'client_secret' => 'sec', 'auth_url' => 'https://fb.test/oauth',
            'token_url' => 'https://fb.test/token', 'endpoint' => 'https://fb.test/post',
        ]);
        \Illuminate\Support\Facades\Http::fake([
            'fb.test/token' => \Illuminate\Support\Facades\Http::response(['access_token' => 'real-token', 'expires_in' => 3600]),
            'fb.test/post' => \Illuminate\Support\Facades\Http::response(['id' => '123', 'permalink' => 'https://facebook.com/p/123']),
        ]);
        $seller = $this->seller();

        $this->actingAs($seller)->get(route('seller.social.connect', 'facebook'))->assertRedirect();
        $state = session('social_oauth.facebook');
        $this->actingAs($seller)->get(route('seller.social.callback', ['platform' => 'facebook', 'code' => 'abc', 'state' => $state]))
            ->assertSessionHas('success');
        $this->assertSame('real-token', SocialAccount::firstOrFail()->access_token);

        Livewire::actingAs($seller)->test(SocialStudio::class)
            ->set('channels', ['facebook'])->set('body', 'Live post')->call('publish')->assertHasNoErrors();

        $target = SocialPost::firstOrFail()->targets()->first();
        $this->assertSame('published', $target->status);
        $this->assertSame('https://facebook.com/p/123', $target->permalink);
    }

    public function test_scheduled_post_publishes_via_maintenance(): void
    {
        $seller = $this->seller();
        SocialAccount::create(['seller_id' => $seller->id, 'platform' => 'instagram', 'access_token' => 'demo_x', 'status' => 'connected']);

        Livewire::actingAs($seller)->test(SocialStudio::class)
            ->set('channels', ['instagram'])
            ->set('body', 'Weekend sale starts now')
            ->set('when', 'later')
            ->set('scheduledAt', now()->addMinutes(5)->format('Y-m-d\TH:i'))
            ->call('publish')
            ->assertHasNoErrors();

        $post = SocialPost::firstOrFail();
        $this->assertSame('scheduled', $post->status);

        $post->update(['scheduled_at' => now()->subMinute()]);
        $this->artisan('marketplace:maintain')->assertSuccessful();

        // Demo token + no platform app → the share kit is ready at the scheduled time.
        $this->assertSame('ready', $post->fresh()->status);
        $this->assertSame('share_kit', $post->targets()->first()->status);
    }

    public function test_ai_caption_falls_back_to_template_without_key(): void
    {
        config()->set('services.anthropic.key', null);
        $seller = $this->seller();
        SocialAccount::create(['seller_id' => $seller->id, 'platform' => 'x', 'access_token' => 'demo_x', 'status' => 'connected']);
        $product = Product::create([
            'seller_id' => $seller->id, 'category_id' => Category::create(['name' => 'Brakes', 'slug' => 'b-'.uniqid()])->id,
            'title' => 'Ceramic Brake Pads', 'slug' => 'cbp-'.uniqid(), 'price' => 59.99, 'quantity' => 10, 'status' => 'active',
        ]);

        $component = Livewire::actingAs($seller)->test(SocialStudio::class)
            ->set('channels', ['x'])
            ->set('productId', $product->id)
            ->call('generate');

        $this->assertNotEmpty($component->get('body'));
        $this->assertLessThanOrEqual(280, strlen($component->get('body')));
    }

    public function test_metrics_refresh_never_invents_engagement(): void
    {
        $seller = $this->seller();
        SocialAccount::create(['seller_id' => $seller->id, 'platform' => 'facebook', 'access_token' => 'demo_x', 'status' => 'connected']);
        $post = SocialPost::create(['seller_id' => $seller->id, 'body' => 'x', 'status' => 'published', 'published_at' => now()->subHour()]);
        $post->targets()->create(['platform' => 'facebook', 'status' => 'published', 'published_at' => now()->subHour(), 'metrics' => ['impressions' => 0, 'clicks' => 0]]);

        $this->assertSame(0, app(SocialPublisher::class)->refreshMetrics());
        $this->assertSame(0, $post->targets()->first()->metrics['impressions']);
    }

    public function test_cannot_disconnect_another_sellers_account(): void
    {
        $me = $this->seller();
        $other = $this->seller();
        $acc = SocialAccount::create(['seller_id' => $other->id, 'platform' => 'x', 'access_token' => 'demo_x', 'status' => 'connected']);

        $this->actingAs($me)->delete(route('seller.social.disconnect', $acc))->assertForbidden();
    }
}
