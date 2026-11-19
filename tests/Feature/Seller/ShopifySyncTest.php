<?php

namespace Tests\Feature\Seller;

use App\Jobs\FetchProductImages;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\ShopifyConnection;
use App\Models\User;
use App\Services\Shopify\ShopifyProductSync;
use Database\Seeders\CategorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Connect Shopify → import → keep in step through signed webhooks. Shopify itself is faked. */
class ShopifySyncTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'shpss_test_secret';

    private const SHOP = 'turbo-parts.myshopify.com';

    private User $seller;

    /** @var array<int, array> what the fake Shopify store currently holds, by product id */
    private array $store = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.shopify.client_id' => 'client-123', 'services.shopify.client_secret' => self::SECRET]);
        $this->seed(RoleSeeder::class);
        $this->seed(CategorySeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seller = User::factory()->create();
        $this->seller->assignRole('seller');
        SellerProfile::create(['user_id' => $this->seller->id, 'business_name' => 'Turbo '.uniqid(), 'business_type' => 'llc', 'status' => 'approved', 'slug' => 't-'.uniqid()]);

        Bus::fake([FetchProductImages::class]); // photo downloads are covered by their own tests
        $this->store[101] = $this->shopifyProduct(101, 'Ceramic Front Brake Pads', 'ACTIVE', [
            ['id' => 9001, 'price' => '49.99', 'compareAtPrice' => '59.99', 'qty' => 12, 'opts' => [['name' => 'Title', 'value' => 'Default Title']]],
        ], ['https://cdn.shopify.com/s/files/pads-1.jpg?v=1', 'https://cdn.shopify.com/s/files/pads-2.jpg?v=1']);
        $this->store[102] = $this->shopifyProduct(102, 'LED Tail Lights for 2015-2017 Ford F-150', 'ACTIVE', [
            ['id' => 9101, 'price' => '269.99', 'qty' => 4, 'opts' => [['name' => 'Color', 'value' => 'Smoked']], 'img' => 'https://cdn.shopify.com/s/files/tl-smoked.jpg?v=2'],
            ['id' => 9102, 'price' => '249.99', 'qty' => 6, 'opts' => [['name' => 'Color', 'value' => 'Clear']]],
        ], ['https://cdn.shopify.com/s/files/tl-1.jpg?v=2']);
        $this->store[103] = $this->shopifyProduct(103, 'Spare Oil Filter (coming soon)', 'DRAFT', [
            ['id' => 9201, 'price' => '9.99', 'qty' => 0, 'opts' => [['name' => 'Title', 'value' => 'Default Title']], 'tracked' => false],
        ], []);

        Http::fake(function (HttpRequest $r) {
            if (str_ends_with($r->url(), '/admin/oauth/access_token')) {
                return Http::response(['access_token' => 'shpat_live', 'scope' => 'read_products,read_inventory', 'expires_in' => 3600, 'refresh_token' => 'shprt_1', 'refresh_token_expires_in' => 7776000]);
            }
            $q = (string) ($r->data()['query'] ?? '');
            $vars = (array) ($r->data()['variables'] ?? []);

            return Http::response(['data' => match (true) {
                str_contains($q, 'webhookSubscriptionCreate') => ['webhookSubscriptionCreate' => ['webhookSubscription' => ['id' => 'gid://shopify/WebhookSubscription/1'], 'userErrors' => []]],
                str_contains($q, 'shop { name }') => ['shop' => ['name' => 'Turbo Parts']],
                str_contains($q, 'products(first') => ['products' => ['nodes' => array_map(fn ($id) => ['id' => "gid://shopify/Product/{$id}"], array_keys($this->store)), 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]],
                str_contains($q, 'inventoryItem(id') => ['inventoryItem' => ['variant' => ['product' => ['id' => 'gid://shopify/Product/101']]]],
                str_contains($q, 'product(id') => ['product' => $this->store[ShopifyProductSync::numericId($vars['id'])] ?? null],
                default => [],
            }]);
        });
    }

    private function shopifyProduct(int $id, string $title, string $status, array $variants, array $photos): array
    {
        return [
            'id' => "gid://shopify/Product/{$id}", 'title' => $title, 'descriptionHtml' => '<p>Great part.</p><script>alert(1)</script>',
            'vendor' => 'Bosch', 'productType' => '', 'status' => $status, 'tags' => [],
            'media' => ['nodes' => array_map(fn ($u, $i) => ['id' => "gid://shopify/MediaImage/{$id}{$i}", 'image' => ['url' => $u]], $photos, array_keys($photos))],
            'variants' => ['nodes' => array_map(fn ($v, $i) => [
                'id' => "gid://shopify/ProductVariant/{$v['id']}", 'title' => $v['opts'][0]['value'], 'sku' => "SKU-{$v['id']}",
                'price' => $v['price'], 'compareAtPrice' => $v['compareAtPrice'] ?? null, 'inventoryQuantity' => $v['qty'], 'position' => $i + 1,
                'selectedOptions' => $v['opts'], 'image' => isset($v['img']) ? ['url' => $v['img']] : null,
                'inventoryItem' => ['tracked' => $v['tracked'] ?? true, 'measurement' => ['weight' => ['unit' => 'POUNDS', 'value' => 2.5]]],
            ], $variants, array_keys($variants))],
        ];
    }

    private function connect(): ShopifyConnection
    {
        $page = $this->actingAs($this->withTwoFactor($this->seller))->get(route('seller.shopify.connect', ['shop' => 'turbo-parts']));
        $page->assertRedirect();
        $this->assertStringStartsWith('https://'.self::SHOP.'/admin/oauth/authorize?', $page->headers->get('Location'));
        parse_str(parse_url($page->headers->get('Location'), PHP_URL_QUERY), $auth);
        $this->assertSame('read_products,read_inventory', $auth['scope']);

        $q = ['code' => 'one-time', 'shop' => self::SHOP, 'state' => $auth['state'], 'timestamp' => (string) time()];
        ksort($q);
        $q['hmac'] = hash_hmac('sha256', collect($q)->map(fn ($v, $k) => "{$k}={$v}")->implode('&'), self::SECRET);

        $this->get(route('seller.shopify.callback', $q))->assertRedirect(route('seller.api.index'));

        return ShopifyConnection::where('seller_id', $this->seller->id)->firstOrFail();
    }

    private function webhook(string $topic, array $payload, ?string $secret = self::SECRET)
    {
        $body = json_encode($payload);

        return $this->call('POST', route('webhooks.shopify'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_TOPIC' => $topic,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => self::SHOP,
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => uniqid('wh', true),
            'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $body, (string) $secret, true)),
        ], $body);
    }

    private function product(string $title): Product
    {
        return Product::withTrashed()->where('seller_id', $this->seller->id)->where('title', $title)->firstOrFail();
    }

    public function test_connecting_imports_every_product_and_subscribes_to_updates(): void
    {
        $c = $this->connect();

        $this->assertTrue($c->isConnected());
        $this->assertSame('Turbo Parts', $c->shop_name);
        $this->assertSame('shpat_live', $c->access_token);
        $this->assertNotSame('shpat_live', \DB::table('shopify_connections')->value('access_token'), 'token is encrypted at rest');
        $this->assertSame('idle', $c->sync_status);
        $this->assertSame(3, $c->links()->count());
        Http::assertSent(fn ($r) => str_contains((string) ($r->data()['query'] ?? ''), 'webhookSubscriptionCreate')
            && (json_decode($r->body(), true)['variables']['topic'] ?? null) === 'PRODUCTS_DELETE'
            && json_decode($r->body(), true)['variables']['sub']['uri'] === route('webhooks.shopify'));

        $pads = $this->product('Ceramic Front Brake Pads');
        $this->assertSame('active', $pads->status, 'Active in Shopify → live here');
        $this->assertSame('brake-pads', $pads->category->slug);
        $this->assertSame('Bosch', $pads->brand->name);
        $this->assertEquals(59.99, (float) $pads->price);
        $this->assertEquals(49.99, (float) $pads->sale_price);
        $this->assertSame(12, (int) $pads->quantity);
        $this->assertSame('SKU-9001', $pads->sku);
        $this->assertSame(0, $pads->variants()->count());
        $this->assertStringNotContainsString('<script', (string) $pads->description);
        Bus::assertDispatched(FetchProductImages::class, fn ($j) => $j->productId === $pads->id && count($j->urls) === 2);

        $lights = $this->product('LED Tail Lights for 2015-2017 Ford F-150');
        $this->assertEqualsCanonicalizing(['Color: Smoked', 'Color: Clear'], $lights->variants()->pluck('label')->all());
        $this->assertSame(10, (int) $lights->fresh()->quantity);
        $this->assertEquals(249.99, (float) $lights->price, 'cheapest option');
        Bus::assertDispatched(FetchProductImages::class, fn ($j) => $j->productId === $lights->id
            && ($j->variantPhotos['Color: Smoked'] ?? null) === 'https://cdn.shopify.com/s/files/tl-smoked.jpg?v=2');

        $draft = $this->product('Spare Oil Filter (coming soon)');
        $this->assertSame('draft', $draft->status, 'Draft in Shopify → draft here');
        $this->assertSame(ShopifyProductSync::UNTRACKED_STOCK, (int) $draft->quantity, 'stock not tracked in Shopify');
    }

    public function test_changes_in_shopify_update_the_same_product(): void
    {
        $this->connect();
        $lights = $this->product('LED Tail Lights for 2015-2017 Ford F-150');

        // Seller renames, reprices, drops the Clear option and sets the item to Draft.
        $p = &$this->store[102];
        $p['title'] = 'LED Tail Lights for 2015-2017 Ford F-150 (pair)';
        $p['status'] = 'DRAFT';
        $p['variants']['nodes'][0]['price'] = '199.00';
        array_pop($p['variants']['nodes']);
        unset($p);

        $this->webhook('products/update', ['id' => 102])->assertOk();

        $lights->refresh();
        $this->assertSame('LED Tail Lights for 2015-2017 Ford F-150 (pair)', $lights->title);
        $this->assertSame('draft', $lights->status);
        $this->assertEquals(199.00, (float) $lights->price);
        $this->assertSame(['Color: Smoked'], $lights->variants()->pluck('label')->all());
        $this->assertSame(4, (int) $lights->quantity);
        $this->assertSame(1, Product::where('seller_id', $this->seller->id)->where('title', 'like', 'LED Tail%')->count(), 'no duplicate');
    }

    public function test_new_and_deleted_products_in_shopify(): void
    {
        $this->connect();

        $this->store[104] = $this->shopifyProduct(104, 'Iridium Spark Plug 4 Pack', 'ACTIVE', [
            ['id' => 9301, 'price' => '39.99', 'qty' => 40, 'opts' => [['name' => 'Title', 'value' => 'Default Title']]],
        ], []);
        $this->webhook('products/create', ['id' => 104])->assertOk();
        $plugs = $this->product('Iridium Spark Plug 4 Pack');
        $this->assertSame('active', $plugs->status);
        $this->assertSame('spark-plugs', $plugs->category->slug);

        unset($this->store[101]);
        $this->webhook('products/delete', ['id' => 101])->assertOk();
        $this->assertSoftDeleted($this->product('Ceramic Front Brake Pads'));
    }

    public function test_stock_changes_in_shopify_reach_the_product(): void
    {
        $this->connect();
        $this->store[101]['variants']['nodes'][0]['inventoryQuantity'] = 3;

        $this->webhook('inventory_levels/update', ['inventory_item_id' => 555, 'available' => 3])->assertOk();

        $this->assertSame(3, (int) $this->product('Ceramic Front Brake Pads')->quantity);
    }

    public function test_unsigned_or_forged_webhooks_are_refused(): void
    {
        $this->connect();
        unset($this->store[101]);

        $this->webhook('products/delete', ['id' => 101], 'wrong-secret')->assertStatus(401);
        $this->assertNotSoftDeleted($this->product('Ceramic Front Brake Pads'));
    }

    public function test_a_forged_sign_in_callback_is_refused(): void
    {
        $this->actingAs($this->withTwoFactor($this->seller))->get(route('seller.shopify.connect', ['shop' => self::SHOP]));

        $this->get(route('seller.shopify.callback', ['code' => 'x', 'shop' => self::SHOP, 'state' => 'guess', 'hmac' => 'bad']))
            ->assertRedirect(route('seller.api.index'))->assertSessionHas('error');
        $this->assertSame(0, ShopifyConnection::count());
    }

    public function test_uninstalling_the_app_in_shopify_disconnects_but_keeps_products(): void
    {
        $this->connect();

        $this->webhook('app/uninstalled', ['id' => 1])->assertOk();

        $c = ShopifyConnection::first();
        $this->assertSame('disconnected', $c->status);
        $this->assertNull($c->access_token);
        $this->assertSame(3, Product::where('seller_id', $this->seller->id)->count());
    }

    public function test_the_api_page_shows_the_shopify_card(): void
    {
        \Livewire\Livewire::actingAs($this->withTwoFactor($this->seller))->test(\App\Livewire\Seller\ApiAccess::class)
            ->assertSee('Connect Shopify')->assertSee('your-store.myshopify.com');

        $this->connect();
        \Livewire\Livewire::actingAs($this->seller)->test(\App\Livewire\Seller\ApiAccess::class)
            ->assertSee('Turbo Parts')->assertSee('Connected')->assertSee('Sync all products now');
    }
}
