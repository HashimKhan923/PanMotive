<?php

namespace Tests\Feature\Ai;

use App\Enums\OrderStatus;
use App\Jobs\GenerateProductSeo;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\AI\AiClient;
use App\Services\AI\FraudScoringService;
use App\Services\AI\ListingAssistantService;
use App\Services\AI\SeoGeneratorService;
use App\Services\Search\SemanticFallbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class AiLayerTest extends TestCase
{
    use RefreshDatabase;

    private function withKey(): void
    {
        config()->set('services.anthropic.key', 'sk-ant-test');
        config()->set('services.anthropic.model', 'claude-sonnet-4-6');
    }

    private function fakeText(string $text): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => $text]]], 200)]);
    }

    // ---------- AiClient ----------

    public function test_ai_client_returns_text_from_messages_api(): void
    {
        $this->withKey();
        $this->fakeText('brake pad');

        $this->assertSame('brake pad', app(AiClient::class)->complete('sys', 'prompt'));
    }

    public function test_ai_client_parses_json_response(): void
    {
        $this->withKey();
        $this->fakeText('{"title":"X","description":"Y"}');

        $this->assertSame(['title' => 'X', 'description' => 'Y'], app(AiClient::class)->completeJson('sys', 'prompt'));
    }

    public function test_ai_client_no_key_makes_no_request(): void
    {
        config()->set('services.anthropic.key', null);
        Http::preventStrayRequests();
        Http::fake();

        $this->assertSame('', app(AiClient::class)->complete('sys', 'prompt'));
        $this->assertSame([], app(AiClient::class)->completeJson('sys', 'prompt'));
        Http::assertNothingSent();
    }

    public function test_ai_client_returns_empty_on_api_failure(): void
    {
        $this->withKey();
        Http::fake(['api.anthropic.com/*' => Http::response('nope', 500)]);

        $this->assertSame('', app(AiClient::class)->complete('sys', 'prompt'));
    }

    // ---------- ListingAssistantService (Phase 3 stub, now real) ----------

    public function test_listing_assistant_generates_draft_from_ai(): void
    {
        $this->withKey();
        $this->fakeText('{"title":"Ceramic Brake Pad Set BP-1","description":"<p>Fits great.</p>","bullet_points":["Low dust","Quiet"]}');

        $draft = app(ListingAssistantService::class)->generateListingDraft('BP-1');

        $this->assertSame('Ceramic Brake Pad Set BP-1', $draft['title']);
        $this->assertStringContainsString('<p>', $draft['description']);
        $this->assertContains('Quiet', $draft['bullet_points']);
    }

    public function test_suggest_price_uses_category_median(): void
    {
        $cat = Category::create(['name' => 'Pads', 'slug' => 'pads-'.Str::random(4)]);
        $seller = User::factory()->create();
        foreach ([50, 100, 150] as $p) {
            Product::create([
                'seller_id' => $seller->id, 'category_id' => $cat->id, 'title' => 'P'.$p,
                'slug' => 'p'.$p.'-'.Str::random(4), 'price' => $p, 'quantity' => 1,
                'condition' => 'new', 'status' => 'active',
            ]);
        }

        $band = app(ListingAssistantService::class)->suggestPrice($cat->id, 'new');

        $this->assertSame(100.0, $band['category_median']);
        $this->assertSame(90.0, $band['suggested_min']);
        $this->assertSame(110.0, $band['suggested_max']);
    }

    // ---------- SEO ----------

    public function test_seo_generator_builds_meta_and_structured_data(): void
    {
        $this->withKey();
        $this->fakeText('{"meta_title":"Buy BP-1 Brake Pads","meta_description":"Fast shipping on BP-1."}');

        $seller = User::factory()->create();
        $product = Product::create([
            'seller_id' => $seller->id,
            'category_id' => Category::create(['name' => 'Pads', 'slug' => 'pads-'.Str::random(4)])->id,
            'title' => 'BP-1 Pads', 'slug' => 'bp1-'.Str::random(4), 'price' => 40, 'quantity' => 3, 'status' => 'active',
        ]);

        $data = app(SeoGeneratorService::class)->generateForProduct($product);

        $this->assertSame('Buy BP-1 Brake Pads', $data['meta_title']);
        $this->assertSame('Product', $data['structured_data']['@type']);
        $this->assertSame('https://schema.org/InStock', $data['structured_data']['offers']['availability']);
    }

    public function test_generate_product_seo_job_updates_product_when_key_present(): void
    {
        $this->withKey();
        $this->fakeText('{"meta_title":"Meta A","meta_description":"Desc A"}');

        $seller = User::factory()->create();
        $product = Product::withoutEvents(fn () => Product::create([
            'seller_id' => $seller->id,
            'category_id' => Category::create(['name' => 'C', 'slug' => 'c-'.Str::random(4)])->id,
            'title' => 'T', 'slug' => 't-'.Str::random(4), 'price' => 10, 'quantity' => 1, 'status' => 'active',
        ]));

        (new GenerateProductSeo($product->id))->handle(app(SeoGeneratorService::class));

        $product->refresh();
        $this->assertSame('Meta A', $product->meta_title);
        $this->assertNotNull($product->structured_data);
    }

    public function test_generate_product_seo_job_is_noop_without_key(): void
    {
        config()->set('services.anthropic.key', null);
        Http::preventStrayRequests();

        $seller = User::factory()->create();
        $product = Product::create([
            'seller_id' => $seller->id,
            'category_id' => Category::create(['name' => 'C', 'slug' => 'c-'.Str::random(4)])->id,
            'title' => 'T', 'slug' => 't-'.Str::random(4), 'price' => 10, 'quantity' => 1, 'status' => 'active',
        ]);

        (new GenerateProductSeo($product->id))->handle(app(SeoGeneratorService::class));

        $this->assertNull($product->refresh()->meta_title);
    }

    // ---------- Semantic fallback ----------

    public function test_semantic_fallback_rewrites_vague_query(): void
    {
        $this->withKey();
        $this->fakeText('brake pad');

        $this->assertSame(
            'brake pad',
            app(SemanticFallbackService::class)->rewriteQuery('thing that stops the wheel from spinning')
        );
    }

    // ---------- Support chat ----------

    public function test_support_chat_appends_reply_and_escalates_on_human(): void
    {
        $this->withKey();
        $this->fakeText('Sure, I can help with that.');

        Livewire::test(\App\Livewire\Storefront\SupportChat::class)
            ->set('input', 'I want to talk to a human')
            ->call('send')
            ->assertSet('input', '')
            ->assertSet('thinking', false);

        $component = Livewire::test(\App\Livewire\Storefront\SupportChat::class)
            ->set('input', 'talk to a human please')
            ->call('send');

        $messages = $component->get('messages');
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('assistant', $messages[1]['role']);
        $this->assertStringContainsString('flagged this for a support agent', $messages[1]['content']);
    }

    // ---------- Fraud scoring ----------

    public function test_fraud_scoring_flags_risky_order_and_alerts_admin(): void
    {
        config()->set('services.stripe.webhook_secret', 'whsec_test');

        $buyer = User::factory()->create(); // created now → account age < 1 day
        $order = Order::create([
            'order_number' => 'PM-'.strtoupper(Str::random(8)),
            'buyer_id' => $buyer->id,
            'status' => OrderStatus::Placed,
            'subtotal' => 400, 'total' => 400,
            'shipping_address' => ['state' => 'CA'],
            'billing_address' => ['state' => 'NY'], // mismatch
            'stripe_payment_intent_id' => 'pi_'.Str::random(10),
        ]);

        $result = app(FraudScoringService::class)->score($order);
        $this->assertTrue($result['flagged']);
        $this->assertSame(50, $result['risk_score']);

        // ...and it surfaces through the webhook into the admin alert inbox.
        $payload = json_encode([
            'id' => 'evt_x', 'object' => 'event', 'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => $order->stripe_payment_intent_id, 'object' => 'payment_intent']],
        ]);
        $t = time();
        $sig = hash_hmac('sha256', "{$t}.{$payload}", 'whsec_test');
        $this->call('POST', route('webhooks.stripe'), [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$t},v1={$sig}", 'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertOk();

        $this->assertDatabaseHas('admin_alerts', ['type' => 'fraud_review']);
    }

    public function test_fraud_scoring_does_not_flag_normal_order(): void
    {
        $buyer = User::factory()->create();
        $order = Order::create([
            'order_number' => 'PM-'.strtoupper(Str::random(8)),
            'buyer_id' => $buyer->id,
            'status' => OrderStatus::Placed,
            'subtotal' => 60, 'total' => 60,
            'shipping_address' => ['state' => 'CA'],
            'billing_address' => ['state' => 'CA'],
            'stripe_payment_intent_id' => 'pi_'.Str::random(10),
        ]);

        $this->assertFalse(app(FraudScoringService::class)->score($order)['flagged']);
    }
}
