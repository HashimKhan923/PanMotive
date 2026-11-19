<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\SyncShopifyProduct;
use App\Models\ShopifyConnection;
use App\Services\Shopify\ShopifyApp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Shopify → Panmotive. Every delivery is signed with our app secret; unsigned or wrongly signed
 * requests are refused. We answer within Shopify's 5 seconds and do the work on the queue.
 */
class ShopifyWebhookController extends Controller
{
    public function handle(Request $request, ShopifyApp $app)
    {
        $raw = $request->getContent();
        if (! $app->validWebhook($raw, $request->header('X-Shopify-Hmac-Sha256'))) {
            return response('Invalid signature', 401);
        }

        $topic = (string) $request->header('X-Shopify-Topic');
        $shop = ShopifyApp::normalizeShop($request->header('X-Shopify-Shop-Domain'));

        // Shopify retries and can deliver the same event twice.
        $deliveryId = (string) $request->header('X-Shopify-Webhook-Id');
        if ($deliveryId !== '' && ! Cache::add("shopify-wh:{$deliveryId}", 1, now()->addDay())) {
            return response('Already handled', 200);
        }

        // Privacy webhooks every Shopify app must accept. We hold no Shopify customer data.
        if (in_array($topic, ['customers/data_request', 'customers/redact', 'shop/redact'], true)) {
            return response('OK', 200);
        }

        $c = $shop ? ShopifyConnection::where('shop_domain', $shop)->first() : null;
        if (! $c) {
            return response('Unknown shop', 200); // not ours (any more) — don't make Shopify retry
        }

        $payload = json_decode($raw, true) ?: [];

        if ($topic === 'app/uninstalled') {
            $c->update(['status' => 'disconnected', 'access_token' => null, 'refresh_token' => null, 'sync_status' => 'idle',
                'last_error' => 'The Panmotive app was removed in Shopify. Products stay here; connect again to resume syncing.']);

            return response('OK', 200);
        }
        if (! $c->isConnected()) {
            return response('Not connected', 200);
        }

        $job = match ($topic) {
            'products/create', 'products/update', 'products/delete' => isset($payload['id'])
                ? new SyncShopifyProduct($c->id, (int) $payload['id'])
                : null,
            'inventory_levels/update' => isset($payload['inventory_item_id'])
                ? new SyncShopifyProduct($c->id, (int) $payload['inventory_item_id'], 'inventory_item')
                : null,
            default => null,
        };
        if ($job) {
            dispatch($job);
            $c->forceFill(['last_event_at' => now()])->saveQuietly();
        }

        return response('OK', 200);
    }
}
