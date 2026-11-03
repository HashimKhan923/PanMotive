<?php

namespace App\Services\Marketing\Social;

class PlatformRegistry
{
    /** @var array<string, array{label:string, char_limit:int, needs_media:bool, hashtags:bool}> */
    public const PLATFORMS = [
        'facebook' => ['label' => 'Facebook', 'char_limit' => 5000, 'needs_media' => false, 'hashtags' => false],
        'instagram' => ['label' => 'Instagram', 'char_limit' => 2200, 'needs_media' => true, 'hashtags' => true],
        'x' => ['label' => 'X (Twitter)', 'char_limit' => 280, 'needs_media' => false, 'hashtags' => true],
        'pinterest' => ['label' => 'Pinterest', 'char_limit' => 500, 'needs_media' => true, 'hashtags' => false],
        'tiktok' => ['label' => 'TikTok', 'char_limit' => 2200, 'needs_media' => true, 'hashtags' => true],
        'linkedin' => ['label' => 'LinkedIn', 'char_limit' => 3000, 'needs_media' => false, 'hashtags' => false],
        'google_business' => ['label' => 'Google Business', 'char_limit' => 1500, 'needs_media' => false, 'hashtags' => false],
        'youtube' => ['label' => 'YouTube Community', 'char_limit' => 2200, 'needs_media' => false, 'hashtags' => true],
    ];

    public static function all(): array
    {
        return self::PLATFORMS;
    }

    public static function label(string $key): string
    {
        return self::PLATFORMS[$key]['label'] ?? ucfirst($key);
    }

    public static function config(string $key): ?array
    {
        return self::PLATFORMS[$key] ?? null;
    }

    public static function isValid(string $key): bool
    {
        return array_key_exists($key, self::PLATFORMS);
    }

    /**
     * Auto-posting needs real platform app credentials (OAuth + publish endpoint). Without them
     * the studio works as a share kit — it never pretends a post went out.
     */
    public static function autoPostConfigured(string $key): bool
    {
        $c = config("services.social.{$key}", []);

        return ! empty($c['client_id']) && ! empty($c['client_secret']) && ! empty($c['auth_url'])
            && ! empty($c['token_url']) && ! empty($c['endpoint']);
    }

    /**
     * One-tap web share link for platforms that offer one; null where the platform has no web
     * share intent (Instagram, TikTok, YouTube, Google Business) — copy caption + download image.
     */
    public static function shareUrl(string $key, string $url, string $text, ?string $imageUrl = null): ?string
    {
        $q = fn (array $p) => http_build_query(array_filter($p), '', '&', PHP_QUERY_RFC3986);

        return match ($key) {
            'facebook' => 'https://www.facebook.com/sharer/sharer.php?'.$q(['u' => $url]),
            'x' => 'https://twitter.com/intent/tweet?'.$q(['text' => $text, 'url' => $url]),
            'linkedin' => 'https://www.linkedin.com/sharing/share-offsite/?'.$q(['url' => $url]),
            'pinterest' => 'https://www.pinterest.com/pin/create/button/?'.$q(['url' => $url, 'media' => $imageUrl, 'description' => $text]),
            default => null,
        };
    }
}
