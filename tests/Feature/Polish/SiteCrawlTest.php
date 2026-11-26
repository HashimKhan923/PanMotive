<?php

namespace Tests\Feature\Polish;

use App\Enums\OrderStatus;
use App\Models\BuyerClaim;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Models\SellerTeamMember;
use App\Models\Subscription;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\SellerContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The whole-site check: loads the demo data plus an order in every state, then visits every page of the
 * shop (as a guest and as a shopper) and of the seller portal (as the owner, staff and a viewer), following
 * every link it is shown. No page may crash, no link may lead to a 4xx, no form may post to a missing route,
 * and no raw Blade may leak into a page.
 */
class SiteCrawlTest extends TestCase
{
    use RefreshDatabase;

    private const SKIP = ['/export', '/download', '/logout', '/feeds/', '/sitemap', '/search/suggest', '/summary', '/ids', '/labels/', '/label.pdf', '/pick-list', '/packing-slip', '/1099', '/go/', '/sp/', '/a/', '/e/', '/m/unsubscribe', '/email/stop', 'stripe', '/connect/'];

    private User $seller;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Queue::fake();
        Http::fake();
        $this->seed(DatabaseSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seller = $this->withTwoFactor(User::where('email', 'seller1@panmotive.test')->firstOrFail());
        $this->buyer = User::where('email', 'buyer1@panmotive.test')->firstOrFail();
        $this->everyState();
    }

    protected function tearDown(): void
    {
        SellerContext::clear();
        parent::tearDown();
    }

    /** One order line in every status, plus a return, a claim, a conversation, an offer, a subscription and a ticket. */
    private function everyState(): void
    {
        $product = Product::where('seller_id', $this->seller->id)->where('status', 'active')->firstOrFail();
        $lines = [];
        foreach (OrderStatus::cases() as $i => $status) {
            $order = Order::create([
                'order_number' => 'PM-CRAWL'.$i, 'buyer_id' => $this->buyer->id, 'status' => $status,
                'subtotal' => 100, 'total' => 100, 'currency' => 'USD',
                'shipping_address' => ['name' => 'B', 'line1' => '1 Main', 'city' => 'Dallas', 'state' => 'TX', 'postal_code' => '75001', 'country' => 'US'],
                'billing_address' => ['name' => 'B', 'line1' => '1 Main', 'city' => 'Dallas', 'state' => 'TX', 'postal_code' => '75001', 'country' => 'US'],
                'stripe_payment_intent_id' => 'pi_crawl_'.$i, 'placed_at' => now()->subDays(5),
            ]);
            $lines[$status->value] = $order->items()->create([
                'seller_id' => $this->seller->id, 'product_id' => $product->id, 'product_title' => $product->title,
                'quantity' => 1, 'unit_price' => 100, 'line_total' => 100, 'commission_rate' => 10,
                'platform_fee_amount' => 10, 'seller_payout_amount' => 90, 'status' => $status,
                'shipped_at' => in_array($status, [OrderStatus::Shipped, OrderStatus::Delivered], true) ? now()->subDays(3) : null,
                'tracking_number' => '1Z999', 'carrier' => 'ups',
            ]);
        }

        $delivered = $lines[OrderStatus::Delivered->value];
        ReturnRequest::create(['order_item_id' => ($lines[OrderStatus::ReturnRequested->value] ?? $delivered)->id, 'user_id' => $this->buyer->id, 'reason' => 'damaged', 'status' => 'requested']);
        BuyerClaim::create(['claim_number' => 'CL-CRAWL', 'order_item_id' => $delivered->id, 'buyer_id' => $this->buyer->id, 'seller_id' => $this->seller->id, 'reason' => 'damaged', 'amount' => 100, 'status' => 'open', 'respond_by' => now()->addDays(2)]);

        $c = Conversation::create(['buyer_id' => $this->buyer->id, 'seller_id' => $this->seller->id, 'product_id' => $product->id, 'subject' => 'Fit?', 'last_message_at' => now(), 'awaiting_reply_since' => now(), 'seller_unread' => 1]);
        Message::create(['conversation_id' => $c->id, 'sender_id' => $this->buyer->id, 'body' => 'Does it fit?']);

        Offer::create(['seller_id' => $this->seller->id, 'buyer_id' => $this->buyer->id, 'product_id' => $product->id, 'offer_price' => 80, 'quantity' => 1, 'status' => 'pending', 'expires_at' => now()->addDay()]);
        Subscription::create(['buyer_id' => $this->buyer->id, 'seller_id' => $this->seller->id, 'product_id' => $product->id, 'quantity' => 1, 'interval_days' => 30, 'discount_pct' => 5, 'status' => 'active', 'next_run_at' => now()->addDay()]);
        SupportTicket::create(['seller_id' => $this->seller->id, 'opened_by' => $this->seller->id, 'category' => array_key_first(SupportTicket::CATEGORIES), 'subject' => 'Help', 'status' => 'open', 'priority' => 'normal', 'last_reply_at' => now()]);

        // Supply Network: seller2 supplies, seller1 resells one of its parts and has a resold order.
        $supplier = User::where('email', 'seller2@panmotive.test')->firstOrFail();
        $supplier->sellerProfile->update(['supply_enabled' => true, 'supply_margin_pct' => 20]);
        $supply = app(\App\Services\Seller\SupplyNetworkService::class);
        $supply->approve($supply->request($this->seller, $supplier, 'Crawl'));
        $copy = $supply->addToStore($this->seller, Product::where('seller_id', $supplier->id)->where('status', 'active')->firstOrFail());
        $resold = Order::create([
            'order_number' => 'PM-CRAWLSN', 'buyer_id' => $this->buyer->id, 'status' => OrderStatus::Paid,
            'subtotal' => 100, 'total' => 100, 'currency' => 'USD', 'placed_at' => now(),
            'shipping_address' => ['name' => 'B', 'line1' => '1 Main', 'city' => 'Dallas', 'state' => 'TX', 'postal_code' => '75001', 'country' => 'US'],
            'billing_address' => ['name' => 'B', 'line1' => '1 Main', 'city' => 'Dallas', 'state' => 'TX', 'postal_code' => '75001', 'country' => 'US'],
        ]);
        $resold->items()->create([
            'seller_id' => $supplier->id, 'product_id' => $copy->source_product_id, 'product_title' => $copy->title,
            'quantity' => 1, 'unit_price' => 100, 'line_total' => 100, 'commission_rate' => 10, 'platform_fee_amount' => 10,
            'seller_payout_amount' => 70, 'status' => OrderStatus::Paid,
            'reseller_id' => $this->seller->id, 'listing_product_id' => $copy->id, 'reseller_margin' => 20, 'reseller_margin_pct' => 20,
        ]);
    }

    private function member(string $preset): User
    {
        $u = $this->withTwoFactor(User::factory()->create(['email' => $preset.Str::random(5).'@example.com']));
        SellerTeamMember::create([
            'owner_id' => $this->seller->id, 'user_id' => $u->id, 'email' => $u->email, 'role' => $preset,
            'abilities' => SellerTeamMember::presetAbilities($preset), 'status' => 'active', 'accepted_at' => now(),
        ]);

        return $u;
    }

    /** Every parameter-free GET page on a host — reached directly, so a 403/redirect is fine there, a crash is not. */
    private function directPages(string $host): array
    {
        $urls = [];
        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true) || $route->parameterNames() || ! $route->getName()) {
                continue;
            }
            try {
                $url = route($route->getName());
            } catch (\Throwable) {
                continue;
            }
            if (parse_url($url, PHP_URL_HOST) === $host && ! $this->skipped($url) && ! str_starts_with((string) parse_url($url, PHP_URL_PATH), '/api')) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private function skipped(string $url): bool
    {
        foreach (self::SKIP as $s) {
            if (str_contains($url, $s)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> problems */
    private function crawl(string $who, ?User $user, string $start, int $limit = 450): array
    {
        $host = parse_url($start, PHP_URL_HOST);
        $problems = [];
        $queue = [[$start, 'link', 'start']];
        foreach ($this->directPages($host) as $u) {
            $queue[] = [$u, 'direct', 'route list'];
        }
        $seen = [];
        $perPath = [];

        while ($queue && count($seen) < $limit) {
            [$url, $via, $from] = array_shift($queue);
            $key = preg_replace('/[?&]page=\d+/', '', $url);
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (isset($seen[$key]) || ($perPath[$path] ?? 0) >= 3) {
                continue;
            }
            $seen[$key] = true;
            $perPath[$path] = ($perPath[$path] ?? 0) + 1;

            $this->app['auth']->forgetGuards();
            SellerContext::clear();
            $response = $user ? $this->actingAs($user)->get($url) : $this->get($url);
            $status = $response->getStatusCode();
            $label = $path.(parse_url($url, PHP_URL_QUERY) ? '?'.parse_url($url, PHP_URL_QUERY) : '');

            if (count($seen) % 20 === 0) {
                fwrite(STDERR, "{$who}: ".count($seen).' pages, '.count($problems)." problems\n");
            }
            if (getenv('CRAWL_LOG') && $problems) {
                file_put_contents(getenv('CRAWL_LOG'), implode("\n", array_unique($problems))."\n");
            }

            if ($status >= 500) {
                $e = $response->exception;
                $problems[] = "{$who}: {$status} on {$label} — ".($e ? class_basename($e).': '.Str::limit($e->getMessage(), 220).' @ '.str_replace(base_path(), '', $e->getFile()).':'.$e->getLine() : '');

                continue;
            }
            if ($status >= 400) {
                if ($via === 'link') {
                    $problems[] = "{$who}: {$status} on {$label} (a link it was shown on {$from})";
                }

                continue;
            }
            if ($status >= 300 || ! str_contains((string) $response->headers->get('Content-Type'), 'html')) {
                continue;
            }

            $html = $response->getContent();
            foreach (['{{ $', '{!! ', '@endif', '@endforeach', '@php', '@endphp', '@csrf'] as $leak) {
                if (str_contains(strip_tags(preg_replace('#<script\b.*?</script>#is', '', $html)), $leak)) {
                    $problems[] = "{$who}: raw Blade '{$leak}' shows on {$label}";
                }
            }

            $dom = new \DOMDocument;
            @$dom->loadHTML($html);
            foreach ($dom->getElementsByTagName('a') as $a) {
                $href = str_replace('&amp;', '&', trim($a->getAttribute('href')));
                if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:') || str_starts_with($href, 'javascript')) {
                    continue;
                }
                if (! str_starts_with($href, 'http')) {
                    $href = 'http://'.$host.'/'.ltrim($href, '/');
                }
                if (parse_url($href, PHP_URL_HOST) === $host && ! $this->skipped($href)) {
                    $queue[] = [strtok($href, '#'), 'link', $label];
                }
            }
            foreach ($dom->getElementsByTagName('form') as $form) {
                $action = $form->getAttribute('action');
                $method = strtoupper($form->getAttribute('method') ?: 'GET');
                if ($action === '' || parse_url($action, PHP_URL_HOST) !== $host) {
                    continue;
                }
                foreach ($form->getElementsByTagName('input') as $input) {
                    if ($input->getAttribute('name') === '_method') {
                        $method = strtoupper($input->getAttribute('value'));
                    }
                }
                try {
                    Route::getRoutes()->match(Request::create($action, $method));
                } catch (\Throwable) {
                    $problems[] = "{$who}: form on {$label} goes to a missing route {$method} {$action}";
                }
            }
        }

        $this->assertGreaterThan(5, count($seen), "{$who} reached almost nothing");
        fwrite(STDERR, "\n{$who}: ".count($seen)." pages\n");

        return $problems;
    }

    private function assertClean(array $problems): void
    {
        $problems = array_values(array_unique($problems));
        $this->assertSame([], $problems, "Site problems:\n".implode("\n", $problems));
    }

    public function test_the_shop_as_a_guest(): void
    {
        $this->assertClean($this->crawl('guest', null, route('home')));
    }

    public function test_the_shop_as_a_signed_in_shopper(): void
    {
        $this->assertClean($this->crawl('shopper', $this->buyer, route('home')));
    }

    public function test_the_seller_portal_as_the_owner(): void
    {
        $this->assertClean($this->crawl('owner', $this->seller, route('seller.dashboard')));
    }

    public function test_the_seller_portal_as_staff_and_a_viewer(): void
    {
        $problems = [];
        foreach (array_filter(['staff', 'viewer'], fn ($p) => ! getenv('CRAWL_ONLY') || getenv('CRAWL_ONLY') === $p) as $preset) {
            $problems = [...$problems, ...$this->crawl($preset, $this->member($preset), route('seller.dashboard'), 250)];
        }
        $this->assertClean($problems);
    }
}
