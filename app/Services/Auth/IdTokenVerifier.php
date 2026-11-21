<?php

namespace App\Services\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Checks the ID token the shopping app gets from native "Sign in with Google / Apple": the signature
 * against the provider's published keys, the issuer, the audience (our own app ids) and the expiry.
 * Only then do we trust the email and account id inside it.
 */
class IdTokenVerifier
{
    private const PROVIDERS = [
        'google' => [
            'keys' => 'https://www.googleapis.com/oauth2/v3/certs',
            'issuers' => ['https://accounts.google.com', 'accounts.google.com'],
        ],
        'apple' => [
            'keys' => 'https://appleid.apple.com/auth/keys',
            'issuers' => ['https://appleid.apple.com'],
        ],
    ];

    /** @return list<string> the app/client ids a token must be issued to */
    public function audiences(string $provider): array
    {
        $ids = match ($provider) {
            'google' => [config('services.google.client_id'), ...explode(',', (string) config('services.google.app_client_ids'))],
            'apple' => explode(',', (string) config('services.apple.client_ids')),
            default => [],
        };

        return array_values(array_filter(array_map('trim', $ids)));
    }

    public function configured(string $provider): bool
    {
        return isset(self::PROVIDERS[$provider]) && $this->audiences($provider) !== [];
    }

    /**
     * @return array{sub: string, email: ?string, email_verified: bool, name: ?string}|null null when the token can't be trusted
     */
    public function verify(string $provider, string $idToken, ?string $nonce = null): ?array
    {
        if (! $this->configured($provider)) {
            return null;
        }

        try {
            JWT::$leeway = 60;
            $claims = (array) JWT::decode($idToken, JWK::parseKeySet($this->keys($provider)));
        } catch (\Throwable $e) {
            // A key rotation: fetch the keys once more and retry.
            try {
                Cache::forget("idtoken-keys.{$provider}");
                $claims = (array) JWT::decode($idToken, JWK::parseKeySet($this->keys($provider)));
            } catch (\Throwable) {
                return null;
            }
        }

        if (! in_array($claims['iss'] ?? null, self::PROVIDERS[$provider]['issuers'], true)) {
            return null;
        }
        $aud = (array) ($claims['aud'] ?? []);
        if (! array_intersect($aud, $this->audiences($provider))) {
            return null;
        }
        // Apple sends a SHA-256 of the nonce the app generated; it stops a stolen token being replayed.
        if ($nonce !== null && $nonce !== '' && isset($claims['nonce'])
            && ! hash_equals((string) $claims['nonce'], hash('sha256', $nonce)) && ! hash_equals((string) $claims['nonce'], $nonce)) {
            return null;
        }

        $verified = $claims['email_verified'] ?? false;

        return [
            'sub' => (string) ($claims['sub'] ?? ''),
            'email' => isset($claims['email']) ? (string) $claims['email'] : null,
            'email_verified' => $verified === true || $verified === 'true',
            'name' => isset($claims['name']) ? (string) $claims['name'] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function keys(string $provider): array
    {
        return Cache::remember("idtoken-keys.{$provider}", now()->addHours(6), fn () => Http::timeout(10)
            ->get(self::PROVIDERS[$provider]['keys'])->throw()->json());
    }
}
