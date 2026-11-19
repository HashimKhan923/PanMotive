<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Jobs\SyncShopifyStore;
use App\Models\ShopifyConnection;
use App\Services\Shopify\ShopifyApp;
use App\Services\Shopify\ShopifyClient;
use App\Services\Shopify\ShopifyWebhooks;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * "Connect Shopify". The seller types their store address (or opens our app from Shopify, which
 * sends a signed link) → Shopify asks them to approve read access → back here with a one-time code
 * → we store the token, subscribe to product/stock webhooks and import every product.
 */
class ShopifyConnectController extends Controller
{
    public function connect(Request $request, ShopifyApp $app)
    {
        if (! $app->configured()) {
            return $this->back('Shopify isn\'t set up on Panmotive yet. Please contact support.');
        }

        // Launched from Shopify (install link / app list): the shop comes signed.
        if ($request->has('hmac')) {
            if (! $app->validQueryHmac($request->query())) {
                return $this->back('That Shopify link could not be verified. Try connecting from this page instead.');
            }
        }

        $shop = ShopifyApp::normalizeShop($request->input('shop'));
        if (! $shop) {
            return $this->back('Enter your Shopify store address, like your-store.myshopify.com.');
        }
        if ($problem = $this->takenByAnotherSeller($shop)) {
            return $this->back($problem);
        }

        $state = Str::random(40);
        session(['shopify_oauth' => ['state' => $state, 'shop' => $shop]]);

        return redirect()->away($app->authorizeUrl($shop, $state, route('seller.shopify.callback')));
    }

    public function callback(Request $request, ShopifyApp $app, ShopifyWebhooks $webhooks)
    {
        $expected = session()->pull('shopify_oauth');
        $shop = ShopifyApp::normalizeShop($request->query('shop'));

        if (! $expected || ! $shop || $shop !== $expected['shop']
            || ! hash_equals((string) $expected['state'], (string) $request->query('state'))
            || ! $app->validQueryHmac($request->query())) {
            return $this->back('The Shopify sign-in could not be verified. Please try again.');
        }
        if ($problem = $this->takenByAnotherSeller($shop)) {
            return $this->back($problem);
        }

        try {
            $t = $app->exchangeCode($shop, (string) $request->query('code'));
        } catch (\Throwable $e) {
            report($e);

            return $this->back('Shopify did not finish the sign-in. Please try again.');
        }

        $c = ShopifyConnection::firstOrNew(['seller_id' => auth()->id()]);
        if ($c->exists && $c->shop_domain !== $shop) {
            $c->links()->delete(); // switched to a different store: its products start fresh
        }
        $c->fill([
            'shop_domain' => $shop,
            'access_token' => $t['access_token'],
            'refresh_token' => $t['refresh_token'] ?? null,
            'token_expires_at' => isset($t['expires_in']) ? now()->addSeconds((int) $t['expires_in']) : null,
            'refresh_expires_at' => isset($t['refresh_token_expires_in']) ? now()->addSeconds((int) $t['refresh_token_expires_in']) : null,
            'scopes' => $t['scope'] ?? null,
            'status' => 'connected',
            'last_error' => null,
        ])->save();

        try {
            $c->update(['shop_name' => ShopifyClient::for($c)->query('{ shop { name } }')['shop']['name'] ?? null]);
            if ($problems = $webhooks->register($c)) {
                $c->update(['last_error' => 'Some updates may not arrive: '.Str::limit(implode('; ', $problems), 400)]);
            }
        } catch (\Throwable $e) {
            report($e);
            $c->update(['last_error' => 'Connected, but setting up live updates failed: '.Str::limit($e->getMessage(), 300)]);
        }

        SyncShopifyStore::dispatch($c->id);

        return redirect()->route('seller.api.index')
            ->with('success', 'Shopify connected — your products are being imported now.');
    }

    private function takenByAnotherSeller(string $shop): ?string
    {
        return ShopifyConnection::where('shop_domain', $shop)->where('seller_id', '!=', auth()->id())->exists()
            ? 'That Shopify store is already connected to another Panmotive seller account.'
            : null;
    }

    private function back(string $error)
    {
        return redirect()->route('seller.api.index')->with('error', $error);
    }
}
