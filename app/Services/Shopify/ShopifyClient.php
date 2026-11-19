<?php

namespace App\Services\Shopify;

use App\Models\ShopifyConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * GraphQL Admin API calls for one connected store. Renews an expiring token before it runs out
 * (one worker at a time — each renewal replaces the refresh token) and waits when Shopify says
 * the store's request budget is used up.
 */
class ShopifyClient
{
    public function __construct(private ShopifyConnection $connection, private ShopifyApp $app) {}

    public static function for(ShopifyConnection $connection): self
    {
        return new self($connection, app(ShopifyApp::class));
    }

    /** @return array<string,mixed> the `data` object */
    public function query(string $query, array $variables = []): array
    {
        for ($attempt = 1; ; $attempt++) {
            $res = Http::withHeaders(['X-Shopify-Access-Token' => $this->token()])
                ->acceptJson()->timeout(30)
                ->post("https://{$this->connection->shop_domain}/admin/api/".config('services.shopify.api_version').'/graphql.json', [
                    'query' => $query,
                    'variables' => (object) $variables,
                ]);

            if ($res->status() === 401 || $res->status() === 403) {
                throw new ShopifyAuthException('Shopify refused access to '.$this->connection->shop_domain.' (HTTP '.$res->status().'). Connect the store again.');
            }
            $throttled = $res->status() === 429
                || collect($res->json('errors') ?? [])->contains(fn ($e) => ($e['extensions']['code'] ?? null) === 'THROTTLED');
            if ($throttled && $attempt < 6) {
                $this->pause($res->json('extensions.cost'), $attempt);

                continue;
            }
            if (! $res->successful() || $res->json('errors')) {
                $msg = $res->json('errors.0.message') ?? ('HTTP '.$res->status());
                throw new \RuntimeException('Shopify API error: '.\Illuminate\Support\Str::limit((string) $msg, 200));
            }

            // Running low on budget: give it a moment so the next call doesn't get throttled.
            $status = $res->json('extensions.cost.throttleStatus');
            if ($status && ($status['currentlyAvailable'] ?? 1000) < 200 && ! app()->runningUnitTests()) {
                usleep((int) min(5_000_000, 1_000_000 * (300 - $status['currentlyAvailable']) / max(1, $status['restoreRate'] ?? 50)));
            }

            return (array) $res->json('data');
        }
    }

    private function pause(?array $cost, int $attempt): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        $need = (float) ($cost['requestedQueryCost'] ?? 200);
        $have = (float) ($cost['throttleStatus']['currentlyAvailable'] ?? 0);
        $rate = (float) ($cost['throttleStatus']['restoreRate'] ?? 50);
        sleep((int) max($attempt, min(20, ceil(($need - $have) / max(1, $rate)))));
    }

    private function token(): string
    {
        $c = $this->connection;
        if (! $c->access_token) {
            throw new ShopifyAuthException('This Shopify store is not connected.');
        }
        if (! $c->token_expires_at || $c->token_expires_at->isAfter(now()->addMinutes(3)) || ! $c->refresh_token) {
            return $c->access_token;
        }

        // Only one worker renews; the others wait and then use the new token.
        return Cache::lock("shopify-refresh:{$c->id}", 30)->block(25, function () use ($c) {
            $c->refresh();
            if ($c->token_expires_at && $c->token_expires_at->isAfter(now()->addMinutes(3))) {
                return $c->access_token;
            }
            $t = $this->app->refresh($c->shop_domain, (string) $c->refresh_token);
            $c->forceFill([
                'access_token' => $t['access_token'],
                'refresh_token' => $t['refresh_token'] ?? $c->refresh_token,
                'token_expires_at' => isset($t['expires_in']) ? now()->addSeconds((int) $t['expires_in']) : null,
                'refresh_expires_at' => isset($t['refresh_token_expires_in']) ? now()->addSeconds((int) $t['refresh_token_expires_in']) : $c->refresh_expires_at,
            ])->save();

            return $c->access_token;
        });
    }
}
