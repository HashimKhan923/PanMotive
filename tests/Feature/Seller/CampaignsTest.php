<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Livewire\Seller\Campaigns;
use App\Mail\CampaignMail;
use App\Models\Category;
use App\Models\MarketingCampaign;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Marketing\AudienceResolver;
use App\Services\Marketing\CampaignSender;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CampaignsTest extends TestCase
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
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'Camp '.uniqid(), 'business_type' => 'llc', 'status' => 'approved', 'slug' => 'camp-'.uniqid()]);

        return $this->withTwoFactor($u);
    }

    private function customerWhoBought(User $seller): User
    {
        $buyer = User::factory()->create();
        $order = Order::create([
            'order_number' => 'PM-'.strtoupper(uniqid()), 'buyer_id' => $buyer->id,
            'subtotal' => 100, 'total' => 100, 'shipping_address' => ['line1' => 'x'], 'billing_address' => ['line1' => 'x'],
            'status' => OrderStatus::Delivered,
        ]);
        $order->items()->create([
            'seller_id' => $seller->id,
            'product_id' => Product::create(['seller_id' => $seller->id, 'category_id' => Category::create(['name' => 'C', 'slug' => 'c-'.uniqid()])->id, 'title' => 'P', 'slug' => 'p-'.uniqid(), 'price' => 100, 'quantity' => 5, 'status' => 'active'])->id,
            'product_title' => 'P', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100,
            'commission_rate' => 10, 'platform_fee_amount' => 10, 'seller_payout_amount' => 90, 'status' => OrderStatus::Delivered,
        ]);

        return $buyer;
    }

    public function test_audience_resolver_counts_customers(): void
    {
        $seller = $this->seller();
        $this->customerWhoBought($seller);
        $this->customerWhoBought($seller);
        User::factory()->create(); // unrelated

        $this->assertSame(2, app(AudienceResolver::class)->count($seller->id, 'all_customers'));
    }

    public function test_send_email_campaign_delivers_and_records_recipients(): void
    {
        Mail::fake();
        $seller = $this->seller();
        $this->customerWhoBought($seller);
        $this->customerWhoBought($seller);

        Livewire::actingAs($seller)->test(Campaigns::class)
            ->call('newCampaign')
            ->set('name', 'Restock blast')
            ->set('channel', 'email')
            ->set('audienceType', 'all_customers')
            ->set('subject', 'Back in stock')
            ->set('body', "Hi {{name}}, shop now: {{shop_url}}")
            ->set('when', 'now')
            ->call('save', true)
            ->assertHasNoErrors();

        Mail::assertSent(CampaignMail::class, 2);
        $campaign = MarketingCampaign::firstOrFail();
        $this->assertSame('sent', $campaign->status);
        $this->assertSame(2, $campaign->stats['recipients']);
        $this->assertSame(2, $campaign->recipients()->count());
    }

    public function test_open_and_click_tracking_and_conversion_attribution(): void
    {
        Mail::fake();
        $seller = $this->seller();
        $buyer = $this->customerWhoBought($seller);

        $campaign = MarketingCampaign::create([
            'seller_id' => $seller->id, 'channel' => 'email', 'name' => 'x',
            'audience' => ['type' => 'all_customers'], 'body' => 'Hi {{name}} {{shop_url}}',
            'subject' => 's', 'status' => 'draft',
        ]);
        app(CampaignSender::class)->send($campaign);

        $recipient = $campaign->recipients()->where('user_id', $buyer->id)->firstOrFail();

        $this->get(route('m.open', $recipient->token))->assertOk()->assertHeader('content-type', 'image/gif');
        $this->assertNotNull($recipient->fresh()->opened_at);

        $this->get(route('m.click', $recipient->token))
            ->assertRedirect(route('sellers.show', $seller->sellerProfile))
            ->assertCookie('pm_campaign', $recipient->token);
        $this->assertNotNull($recipient->fresh()->clicked_at);

        // A later purchase from that seller attributes back.
        $product = Product::where('seller_id', $seller->id)->first();
        $this->actingAs($buyer)
            ->withCookie('pm_campaign', $recipient->token)
            ->post(route('cart.add'), ['product_id' => $product->id, 'quantity' => 1]);
        $this->actingAs($buyer)
            ->withCookie('pm_campaign', $recipient->token)
            ->post(route('checkout.store'), [
                'name' => 'B', 'line1' => '1 A St', 'city' => 'X', 'state' => 'CA', 'postal_code' => '90001', 'country' => 'US',
            ]);

        $this->assertNotNull($recipient->fresh()->converted_order_id);
        $this->assertGreaterThan(0, (float) $recipient->fresh()->conversion_value);
    }

    public function test_sms_is_refused_without_a_gateway_never_faked(): void
    {
        config()->set('services.twilio', ['sid' => null, 'token' => null, 'from' => null]);
        $seller = $this->seller();
        $this->customerWhoBought($seller);

        Livewire::actingAs($seller)->test(Campaigns::class)
            ->call('newCampaign')
            ->set('name', 'SMS blast')->set('channel', 'sms')
            ->set('audienceType', 'all_customers')
            ->set('body', 'Flash sale at {{store}} — {{shop_url}}')
            ->call('save', true)
            ->assertHasErrors('channel');

        $this->assertSame(0, MarketingCampaign::count());
    }

    public function test_sms_goes_only_to_opted_in_buyers_through_twilio(): void
    {
        config()->set('services.twilio', ['sid' => 'AC1', 'token' => 't', 'from' => '+15550001111']);
        \Illuminate\Support\Facades\Http::fake(['api.twilio.com/*' => \Illuminate\Support\Facades\Http::response(['sid' => 'SM1'], 201)]);
        $seller = $this->seller();
        $optedIn = $this->customerWhoBought($seller);
        $optedIn->forceFill(['phone' => '+15551234567', 'sms_opt_in_at' => now()])->save();
        $this->customerWhoBought($seller)->forceFill(['phone' => '+15559876543'])->save(); // has a phone, never opted in

        Livewire::actingAs($seller)->test(Campaigns::class)
            ->call('newCampaign')
            ->set('name', 'SMS blast')->set('channel', 'sms')
            ->set('audienceType', 'all_customers')
            ->set('body', 'Flash sale at {{store}}')
            ->call('save', true)
            ->assertHasNoErrors();

        $campaign = MarketingCampaign::firstOrFail();
        $this->assertSame('sent', $campaign->status);
        $this->assertSame(1, $campaign->stats['recipients']);
        \Illuminate\Support\Facades\Http::assertSentCount(1);
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => $r['To'] === '+15551234567' && str_contains($r['Body'], 'Reply STOP'));
    }
}
