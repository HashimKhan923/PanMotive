<?php

namespace App\Services\Shopify;

use Illuminate\Support\Facades\Http;

/**
 * The Panmotive app registered with Shopify: sign-in (OAuth authorization code grant) and
 * signature checks for OAuth callbacks and webhooks. Every check uses the app's client secret.
 */
class ShopifyApp
{
    public function configured(): bool
    {
        return filled(config('services.shopify.client_id')) && filled(config('services.shopify.client_secret'));
    }

    /** "my-store", "my-store.myshopify.com" or "https://my-store.myshopify.com/admin" → "my-store.myshopify.com", or null. */
    public static function normalizeShop(?string $input): ?string
    {
        $shop = strtolower(trim((string) $input));
        $shop = preg_replace('#^https?://#', '', $shop);
        $shop = explode('/', (string) $shop)[0];
        if ($shop !== '' && ! str_contains($shop, '.')) {
            $shop .= '.myshopify.com';
        }

        return preg_match('/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/', $shop) ? $shop : null;
    }

    public function authorizeUrl(string $shop, string $state, string $redirectUri): string
    {
        return "https://{$shop}/admin/oauth/authorize?".http_build_query([
            'client_id' => config('services.shopify.client_id'),
            'scope' => config('services.shopify.scopes'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    /** OAuth redirects and app-launch links: hex HMAC-SHA256 of the other query params, sorted. */
    public function validQueryHmac(array $query): bool
    {
        $given = (string) ($query['hmac'] ?? '');
        if ($given === '' || ! $this->configured()) {
            return false;
        }
        unset($query['hmac'], $query['signature']);
        ksort($query);
        $message = collect($query)->map(fn ($v, $k) => $k.'='.(is_array($v) ? '["'.implode('", "', $v).'"]' : $v))->implode('&');

        return hash_equals(hash_hmac('sha256', $message, (string) config('services.shopify.client_secret')), $given);
    }

    /** Webhooks: base64 HMAC-SHA256 of the raw body. */
    public function validWebhook(string $rawBody, ?string $header): bool
    {
        if (! $this->configured() || blank($header)) {
            return false;
        }
        $expected = base64_encode(hash_hmac('sha256', $rawBody, (string) config('services.shopify.client_secret'), true));

        return hash_equals($expected, (string) $header);
    }

    /**
     * Swap the one-time code for tokens. Asks for an expiring offline token (Shopify's current rule
     * for new apps); the client refreshes it with the refresh token when it runs out.
     *
     * @return array{access_token:string, scope:?string, expires_in:?int, refresh_token:?string, refresh_token_expires_in:?int}
     */
    public function exchangeCode(string $shop, string $code): array
    {
        $res = Http::asForm()->timeout(20)->post("https://{$shop}/admin/oauth/access_token", [
            'client_id' => config('services.shopify.client_id'),
            'client_secret' => config('services.shopify.client_secret'),
            'code' => $code,
            'expiring' => 1,
        ]);
        if (! $res->successful() || ! $res->json('access_token')) {
            throw new \RuntimeException('Shopify did not accept the sign-in (HTTP '.$res->status().').');
        }

        return $res->json();
    }

    /** @return array{access_token:string, expires_in:?int, refresh_token:?string, refresh_token_expires_in:?int} */
    public function refresh(string $shop, string $refreshToken): array
    {
        $res = Http::asForm()->timeout(20)->post("https://{$shop}/admin/oauth/access_token", [
            'client_id' => config('services.shopify.client_id'),
            'client_secret' => config('services.shopify.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
        if (! $res->successful() || ! $res->json('access_token')) {
            throw new ShopifyAuthException('Shopify refused to renew access (HTTP '.$res->status().'). Connect the store again.');
        }

        return $res->json();
    }
}
