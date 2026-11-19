<?php

namespace App\Services\Shopify;

use App\Models\ShopifyConnection;

/** Asks Shopify to tell us about product and stock changes in a connected store. */
class ShopifyWebhooks
{
    public const TOPICS = ['PRODUCTS_CREATE', 'PRODUCTS_UPDATE', 'PRODUCTS_DELETE', 'INVENTORY_LEVELS_UPDATE', 'APP_UNINSTALLED'];

    /** @return list<string> problems (empty when every subscription is in place) */
    public function register(ShopifyConnection $c): array
    {
        $client = ShopifyClient::for($c);
        $uri = route('webhooks.shopify');
        $problems = [];

        foreach (self::TOPICS as $topic) {
            $data = $client->query(<<<'GQL'
                mutation($topic: WebhookSubscriptionTopic!, $sub: WebhookSubscriptionInput!) {
                  webhookSubscriptionCreate(topic: $topic, webhookSubscription: $sub) {
                    webhookSubscription { id }
                    userErrors { field message }
                  }
                }
                GQL, ['topic' => $topic, 'sub' => ['uri' => $uri]]);

            foreach ($data['webhookSubscriptionCreate']['userErrors'] ?? [] as $err) {
                // Already subscribed (e.g. connecting again) is fine.
                if (! str_contains(strtolower((string) $err['message']), 'already been taken')) {
                    $problems[] = "{$topic}: {$err['message']}";
                }
            }
        }

        return $problems;
    }
}
