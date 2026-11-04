<?php

namespace App\Services\Payments;

use App\Models\AdCreditPurchase;
use Stripe\StripeClient;

/** The only place ad-credit purchases talk to Stripe — kept thin so it can be mocked. */
class AdCreditGateway
{
    public function isConfigured(): bool
    {
        return filled(config('services.stripe.secret'));
    }

    /** @return array{id:string, url:string} */
    public function createSession(AdCreditPurchase $purchase, string $successUrl, string $cancelUrl, ?string $email): array
    {
        $session = $this->client()->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'usd',
                    'unit_amount' => (int) round((float) $purchase->amount * 100),
                    'product_data' => [
                        'name' => 'PanMotive ad credit',
                        'description' => 'Prepaid balance for sponsored listings, boosts and ad campaigns.',
                    ],
                ],
            ]],
            'customer_email' => $email,
            'client_reference_id' => (string) $purchase->seller_id,
            'metadata' => ['kind' => 'ad_credit', 'ad_credit_purchase_id' => (string) $purchase->id],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ]);

        return ['id' => $session->id, 'url' => $session->url];
    }

    /** @return array{paid:bool, payment_intent:?string, purchase_id:?string} */
    public function retrieveSession(string $sessionId): array
    {
        $s = $this->client()->checkout->sessions->retrieve($sessionId);

        return [
            'paid' => $s->payment_status === 'paid',
            'payment_intent' => is_string($s->payment_intent) ? $s->payment_intent : ($s->payment_intent->id ?? null),
            'purchase_id' => $s->metadata['ad_credit_purchase_id'] ?? null,
        ];
    }

    private function client(): StripeClient
    {
        return new StripeClient(config('services.stripe.secret'));
    }
}
