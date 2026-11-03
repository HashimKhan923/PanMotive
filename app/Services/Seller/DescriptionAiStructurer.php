<?php

namespace App\Services\Seller;

use App\Services\AI\AiClient;
use Illuminate\Support\Facades\Cache;

/**
 * Fallback for descriptions DescriptionFormatter can't fully section — real
 * feeds are often only *partially* marked up (a couple of emoji near the top,
 * then long uninterrupted prose for "Key Highlights" / "What's in the Box" /
 * "Installation Notes" etc. with no delimiter at all). No regex can section
 * text that carries no structural signal; that's an AI-shaped problem, not a
 * parsing one. Used only when the seller has AI enabled and a key is
 * configured — everything still works without it, just less polished.
 *
 * Results are cached indefinitely by content hash so the same description
 * never gets billed twice (re-imports, re-renders, retries all hit cache).
 */
class DescriptionAiStructurer
{
    private const MIN_LENGTH = 250;

    public function __construct(private AiClient $ai) {}

    public function structure(string $raw): ?string
    {
        $raw = trim($raw);
        if (! $this->ai->isConfigured() || mb_strlen($raw) < self::MIN_LENGTH) {
            return null;
        }

        $key = 'desc-ai-structure:'.md5($raw);
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached !== '' ? $cached : null;
        }

        $html = $this->ask($raw);
        // Cache a real result for a long time; cache a failure only briefly so a
        // transient outage doesn't permanently lock a listing out of AI polish.
        Cache::put($key, $html ?? '', now()->addDays($html ? 180 : 1));

        return $html;
    }

    private function ask(string $raw): ?string
    {
        $system = <<<'SYS'
            You reformat messy plain-text e-commerce product descriptions into clean,
            well-structured HTML, the way a professional listing on a major retail site
            reads. Rules:
            - Preserve every fact, spec, number, and claim from the source exactly — never
              invent, drop, or exaggerate anything.
            - Use ONLY these tags: <p> <h3> <ul> <li> <strong> <em> <blockquote>. No other
              tags, no attributes, no classes, no markdown, no code fences.
            - A short opening paragraph, then logical sections with an <h3> heading where
              the source implies one (e.g. features, fitment, what's included,
              installation, restrictions), each followed by a <p> or <ul><li> list as fits.
            - Wrap genuine cautions/restrictions (not compatible with X, warranty voids
              if..., etc.) in a <blockquote>.
            - Strip any emoji, decorative symbols, or ALL-CAPS shouting from the output —
              express the same section as a normal-case heading instead.
            - Output the HTML fragment only. No preamble, no explanation.
            SYS;

        $out = $this->ai->complete($system, mb_substr($raw, 0, 6000), 1400);
        $out = trim((string) preg_replace('/^```(?:html)?|```$/mu', '', $out));

        return $out !== '' ? $out : null;
    }
}
