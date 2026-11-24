<?php

namespace App\Http\Resources\Shop;

/**
 * Turns a website link (a banner button, a notification, a promo) into a screen in the shopping app.
 * Anything the app has no screen for opens in the in-app browser.
 *
 * Output: ['screen' => 'product'|'shop'|'seller'|'deals'|'plus'|'find-parts'|'fit-guarantee'|'orders'|'order'|'cart'|'wishlist'|'wallet'|'home'|'web', ...params]
 */
class AppLink
{
    /** @return array<string, mixed> */
    public static function from(?string $url): array
    {
        $url = trim((string) $url);
        if ($url === '') {
            return ['screen' => 'home'];
        }

        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $ourHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $isOurs = $host === '' || $host === $ourHost || $host === 'www.'.$ourHost || 'www.'.$host === $ourHost;
        if (! $isOurs) {
            return ['screen' => 'web', 'url' => $url];
        }

        $path = '/'.trim((string) ($parts['path'] ?? '/'), '/');
        parse_str((string) ($parts['query'] ?? ''), $query);

        return match (true) {
            $path === '/' => ['screen' => 'home'],
            (bool) preg_match('#^/products/([^/]+)$#', $path, $m) => ['screen' => 'product', 'slug' => $m[1]],
            (bool) preg_match('#^/sellers/([^/]+)$#', $path, $m) => ['screen' => 'seller', 'slug' => $m[1]],
            (bool) preg_match('#^/@([^/]+)$#', $path, $m) => ['screen' => 'seller', 'slug' => $m[1]],
            (bool) preg_match('#^/orders/([^/]+)$#', $path, $m) => ['screen' => 'order', 'id' => $m[1]],
            $path === '/shop' => ['screen' => 'shop', 'params' => array_intersect_key($query, array_flip(['q', 'category', 'condition', 'make', 'model', 'vehicleId', 'sort']))],
            in_array($path, ['/deals', '/plus', '/membership', '/find-parts', '/fit-guarantee', '/orders', '/cart', '/wishlist', '/wallet', '/messages', '/subscriptions', '/track'], true)
                => ['screen' => ltrim($path, '/')],
            default => ['screen' => 'web', 'url' => $host === '' ? rtrim((string) config('app.url'), '/').$path.(isset($parts['query']) ? '?'.$parts['query'] : '') : $url],
        };
    }
}
