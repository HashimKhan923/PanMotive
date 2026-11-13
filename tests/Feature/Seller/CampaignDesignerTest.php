<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Livewire\Seller\CampaignDesigner;
use App\Livewire\Seller\CampaignReport;
use App\Mail\DesignedCampaignMail;
use App\Models\CampaignClick;
use App\Models\CampaignRecipient;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\MarketingCampaign;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Marketing\CampaignDesign;
use App\Services\Marketing\CampaignRenderer;
use App\Services\Marketing\CampaignSender;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Drag-and-drop campaign emails: designing, safety, sending, tracking and the report. */
class CampaignDesignerTest extends TestCase
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

    private function product(array $attrs = []): Product
    {
        return Product::create(array_merge([
            'seller_id' => $this->seller->id, 'category_id' => Category::firstOrCreate(['slug' => 'c'], ['name' => 'C'])->id,
            'title' => 'Brake Pads '.Str::random(3), 'slug' => 'bp-'.Str::random(8), 'price' => 49.99, 'quantity' => 5, 'status' => 'active',
        ], $attrs));
    }

    private function buyer(string $name = 'Kevin Lee'): User
    {
        $b = User::factory()->create(['name' => $name]);
        $order = Order::create([
            'order_number' => 'PM-'.strtoupper(Str::random(8)), 'buyer_id' => $b->id, 'status' => OrderStatus::Delivered,
            'subtotal' => 10, 'shipping_total' => 0, 'discount_total' => 0, 'credits_used' => 0, 'total' => 10, 'placed_at' => now(),
            'shipping_address' => ['name' => 'K'], 'billing_address' => ['name' => 'K'],
        ]);
        $p = $this->product();
        $order->items()->create(['seller_id' => $this->seller->id, 'product_id' => $p->id, 'product_title' => $p->title, 'quantity' => 1,
            'unit_price' => 10, 'line_total' => 10, 'commission_rate' => 10, 'platform_fee_amount' => 1, 'seller_payout_amount' => 9, 'status' => OrderStatus::Delivered]);

        return $b;
    }

    private function campaign(array $design, array $attrs = []): MarketingCampaign
    {
        return MarketingCampaign::create(array_merge([
            'seller_id' => $this->seller->id, 'channel' => 'email', 'name' => 'Spring', 'subject' => 'New parts for you, {first_name}',
            'preview' => 'Fresh stock', 'body' => '', 'audience' => ['type' => 'all_customers'], 'status' => 'draft',
            'design' => CampaignDesign::sanitize($design, $this->seller->id),
        ], $attrs));
    }

    public function test_sanitize_keeps_only_known_blocks_own_products_and_safe_links(): void
    {
        $mine = $this->product();
        $other = Product::create(['seller_id' => User::factory()->create()->id, 'category_id' => $mine->category_id, 'title' => 'Not mine', 'slug' => 'nm', 'price' => 1, 'quantity' => 1, 'status' => 'active']);

        $clean = CampaignDesign::sanitize([
            ['id' => 'b12345678', 'type' => 'product', 'props' => ['product_id' => $other->id, 'button_label' => 'Buy']],
            ['id' => 'b23456789', 'type' => 'products', 'props' => ['product_ids' => [$mine->id, $other->id], 'columns' => 7]],
            ['type' => 'script', 'props' => ['html' => '<script>']],
            ['type' => 'button', 'props' => ['link' => 'custom', 'custom_url' => 'javascript:alert(1)']],
            ['type' => 'text', 'props' => ['text' => '<b>hi</b>', 'align' => 'sideways']],
        ], $this->seller->id);

        $this->assertCount(4, $clean);
        $this->assertNull($clean[0]['props']['product_id']);
        $this->assertSame([$mine->id], $clean[1]['props']['product_ids']);
        $this->assertSame(2, $clean[1]['props']['columns']);
        $this->assertSame('', $clean[2]['props']['custom_url']);
        $this->assertSame('left', $clean[3]['props']['align']);

        // Text is shown as text, never as HTML.
        $html = app(CampaignRenderer::class)->render($this->campaign($clean), $this->buyer(), 'tok', 'preview')['html'];
        $this->assertStringContainsString('&lt;b&gt;hi&lt;/b&gt;', $html);
    }

    public function test_designer_starts_from_a_template_autosaves_and_checks_quality(): void
    {
        $c = $this->campaign([], ['subject' => null]);

        Livewire::actingAs($this->seller)->test(CampaignDesigner::class, ['campaign' => $c])
            ->assertSee('Pick a starting point')->assertSee('Parts for their car')
            ->call('useStarter', 'sale')
            ->assertSet('choosing', false)
            ->assertSee('Add a subject line.')->assertSee('pick a coupon');

        $this->assertSame(['heading', 'text', 'coupon', 'smart_products'], collect($c->fresh()->design)->pluck('type')->all());

        $coupon = Coupon::create(['code' => 'SPRING10', 'type' => 'percentage', 'value' => 10, 'seller_id' => $this->seller->id, 'is_active' => true]);
        $design = $c->fresh()->design;
        $design[2]['props']['coupon_id'] = $coupon->id;

        Livewire::actingAs($this->seller)->test(CampaignDesigner::class, ['campaign' => $c])
            ->set('subject', 'Spring sale')
            ->call('save', $design, ['color' => '#1d4ed8', 'button' => 'pill', 'header' => 'light'])
            ->assertDontSee('pick a coupon');

        $this->assertSame($coupon->id, $c->fresh()->design[2]['props']['coupon_id']);
        $this->assertSame('#1D4ED8', $this->seller->sellerProfile->fresh()->email_brand['color']);

        $html = $this->actingAs($this->seller)->get(route('seller.campaigns.preview', $c))->assertOk()->getContent();
        $this->assertStringContainsString('SPRING10', $html);
        $this->assertStringContainsString('10% off', $html);
        $this->assertStringContainsString('#1D4ED8', $html);
    }

    public function test_templates_images_and_other_sellers_are_kept_apart(): void
    {
        Storage::fake('public');
        $c = $this->campaign([CampaignDesign::block('heading')]);

        Livewire::actingAs($this->seller)->test(CampaignDesigner::class, ['campaign' => $c])
            ->set('templateName', 'My spring look')->call('saveTemplate')
            ->set('photoFor', 'b1')->set('photo', UploadedFile::fake()->image('banner.jpg', 1200, 600))
            ->assertDispatched('campaign-image');

        $this->assertDatabaseHas('campaign_templates', ['seller_id' => $this->seller->id, 'name' => 'My spring look']);
        $this->assertDatabaseCount('campaign_images', 1);

        $intruder = User::factory()->create();
        $intruder->assignRole('seller');
        SellerProfile::create(['user_id' => $intruder->id, 'business_name' => 'Other', 'business_type' => 'llc', 'status' => 'approved', 'slug' => 'other']);
        $this->actingAs($this->withTwoFactor($intruder))->get(route('seller.campaigns.design', $c))->assertNotFound();
        $this->actingAs($intruder)->get(route('seller.campaigns.preview', $c))->assertNotFound();
        $this->actingAs($intruder)->get(route('seller.campaigns.report', $c))->assertNotFound();
    }

    public function test_sending_personalises_tracks_each_link_and_ignores_bot_clicks(): void
    {
        Mail::fake();
        $p = $this->product(['title' => 'Bosch Rotor']);
        $kevin = $this->buyer('Kevin Lee');
        $c = $this->campaign([CampaignDesign::block('text', ['text' => 'Hi {first_name}!']), CampaignDesign::block('product', ['product_id' => $p->id])]);

        app(CampaignSender::class)->send($c);

        Mail::assertSent(DesignedCampaignMail::class, function ($m) use ($kevin) {
            return $m->hasTo($kevin->email) && $m->subjectLine === 'New parts for you, Kevin'
                && str_contains($m->bodyHtml, 'Hi Kevin!') && str_contains($m->bodyHtml, 'Bosch Rotor') && str_contains($m->bodyHtml, 'Unsubscribe from AutoPro Parts')
                && $m->envelope()->from->name === 'AutoPro Parts via Panmotive';
        });
        $this->assertSame('sent', $c->fresh()->status);

        // Pull the tracked product link out of the email that was sent.
        $sent = Mail::sent(DesignedCampaignMail::class)->first();
        preg_match('~href="([^"]*/e/c/[^"]+(?::|%3A)p'.$p->id.'[^"]*)"~i', html_entity_decode($sent->bodyHtml), $m);
        $this->assertNotEmpty($m, 'product link is tracked');
        $url = html_entity_decode($m[1]);
        $recipient = CampaignRecipient::first();
        $recipient->forceFill(['sent_at' => now()->subMinutes(5)])->save();

        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120'])->get($url)->assertRedirect(route('products.show', $p->slug));
        $this->withHeaders(['User-Agent' => 'Mimecast URL protection scanner'])->get($url)->assertRedirect(route('products.show', $p->slug));

        $this->assertSame(2, CampaignClick::count());
        $this->assertSame(1, CampaignClick::where('is_bot', false)->count());
        $this->assertSame(1, $recipient->fresh()->clicks);

        // A tampered destination can't be used to redirect anywhere else.
        $evil = preg_replace('/u=[^&]+/', 'u='.urlencode('https://evil.example'), $url);
        $this->get($evil)->assertRedirect(route('sellers.show', $this->seller->sellerProfile));

        // The report shows it, with the click map and people list.
        Livewire::actingAs($this->seller)->test(CampaignReport::class, ['campaign' => $c])
            ->assertSee('Bosch Rotor')->assertSee('Kevin Lee')->assertSee('Click map');
        $map = $this->actingAs($this->seller)->get(route('seller.campaigns.preview', ['campaign' => $c, 'report' => 1]))->getContent();
        $this->assertStringContainsString('1 click', $map);
        $this->actingAs($this->seller)->get(route('seller.campaigns.export', $c))->assertOk()->assertDownload('campaign-'.$c->id.'-report.csv');
    }

    public function test_on_gmail_the_daily_cap_pauses_and_resumes_a_campaign(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'marketplace.marketing.campaign_daily_cap' => 2]);
        foreach (range(1, 3) as $i) {
            $this->buyer("Buyer {$i}");
        }
        $c = $this->campaign([CampaignDesign::block('text'), CampaignDesign::block('button')]);

        app(CampaignSender::class)->send($c);
        $this->assertSame('capped', $c->fresh()->status);
        $this->assertSame(1, $c->fresh()->stats['waiting']);
        Mail::assertSent(DesignedCampaignMail::class, 2);

        $this->travel(1)->days();
        app(CampaignSender::class)->resumeCapped();
        $this->assertSame('sent', $c->fresh()->status);
        Mail::assertSent(DesignedCampaignMail::class, 3);

        config(['mail.default' => 'postmark']);
        $this->assertNull(CampaignSender::remainingToday()); // no cap with a bulk email service
    }

    public function test_campaigns_with_design_problems_cannot_be_sent(): void
    {
        $c = $this->campaign([CampaignDesign::block('product')]); // no product picked

        Livewire::actingAs($this->seller)->test(\App\Livewire\Seller\Campaigns::class)
            ->call('sendDraft', $c->id)->assertDispatched('toast');
        $this->assertSame('draft', $c->fresh()->status);

        $issues = CampaignDesign::quality('FREE STUFF!!!', '', $c->design, $this->seller->id);
        $this->assertContains('error', array_column($issues, 'level'));
        $this->assertTrue(collect($issues)->contains(fn ($i) => str_contains($i['text'], 'capital letters')));
    }
}
