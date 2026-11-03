<?php

namespace App\Services\Integrations;

/**
 * Sellers give us a URL and our server calls it — so it must never point inside our own network
 * (SSRF). Only https, only hosts that resolve to public IPs. Checked when the endpoint is saved AND
 * right before every delivery, because DNS can change after it was saved.
 */
class WebhookUrlGuard
{
    /** @return string|null why the URL is refused, or null when it's safe */
    public static function problem(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        if ($scheme !== 'https' && ! (app()->environment('local', 'testing') && $scheme === 'http')) {
            return 'Webhook URLs must use https://';
        }
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return 'That host isn\'t reachable from the internet.';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Remove the username/password from the URL — verify our signature instead.';
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);
        if ($ips === []) {
            return 'We couldn\'t look up that host.';
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return 'That address points to a private or reserved network.';
            }
        }

        return null;
    }

    /** Tests swap in a fake DNS answer so they never depend on the network. */
    public static ?\Closure $resolver = null;

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        if (self::$resolver) {
            return (self::$resolver)($host);
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records)));
    }
}
