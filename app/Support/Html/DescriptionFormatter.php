<?php

namespace App\Support\Html;

/**
 * Many real supplier/Shopify feeds ship descriptions as one long plain-text
 * string that uses emoji as poor-man's section markers instead of real HTML —
 * e.g. "...text... 🔥 PRODUCT FEATURES ✅ Genuine carbon fiber ✅ Lightweight
 * ⚠️ Not compatible with AWD models 🚗 VEHICLE FITMENT 2018 | 2019 | ...".
 *
 * DescriptionSanitizer correctly leaves plain text untouched (so nothing ever
 * breaks), but that means it renders as one flat wall of text. This class
 * recognises the emoji-marker convention and rebuilds it into real semantic
 * HTML (headings, bullet lists, a callout for warnings) *before* it reaches
 * the sanitizer — so it ends up looking like a properly authored listing
 * instead of a raw text dump.
 *
 * Runs only against plain text (no existing HTML tags) and only when at
 * least two emoji markers are found; otherwise returns null so the caller
 * keeps the original text as-is.
 */
class DescriptionFormatter
{
    /** Symbol/pictographic emoji, optionally followed by a variation selector. */
    private const MARKER = '/\p{So}\x{FE0F}?/u';

    /**
     * Universally understood as a caution/warning glyph — always becomes a callout, not a bullet.
     * Deliberately narrow: "❗"/"❓" are commonly used for plain emphasis or FAQ questions, not
     * warnings, so treating them as callouts mislabels ordinary content as a caution.
     */
    private const WARNING_GLYPHS = ["\u{26A0}", "\u{1F6D1}"];

    public function format(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '' || preg_match('/<[a-z][^>]*>/i', $raw)) {
            return null; // empty, or already real HTML — leave it to the sanitizer.
        }

        $raw = preg_replace('/\s+/u', ' ', $raw);

        $parts = preg_split(self::MARKER, $raw, -1, PREG_SPLIT_NO_EMPTY);
        $markers = [];
        preg_match_all(self::MARKER, $raw, $markers);
        $markers = $markers[0];

        if (count($markers) < 2) {
            return null; // not this convention — too few markers to be worth restructuring.
        }

        // preg_split without DELIM_CAPTURE drops the markers; re-pair them positionally.
        // $parts[0] is lead text before the first marker (may be '' if text starts with an emoji).
        $lead = trim(array_shift($parts) ?? '');
        $segments = array_values(array_filter(array_map(
            fn ($marker, $text) => ['marker' => $marker, 'text' => trim((string) $text)],
            $markers,
            $parts
        ), fn ($s) => $s['text'] !== ''));

        $html = '';
        if ($lead !== '') {
            $html .= '<p>'.$this->leadParagraph($lead).'</p>';
        }

        $bullets = [];
        foreach ($segments as $seg) {
            // Marker emoji carry an optional variation-selector byte (U+FE0F) that
            // makes an otherwise-identical glyph fail a strict string comparison —
            // strip it before checking against the known warning glyphs.
            $baseMarker = str_replace("\u{FE0F}", '', $seg['marker']);

            if (in_array($baseMarker, self::WARNING_GLYPHS, true)) {
                $html .= $this->flushBullets($bullets);
                $html .= '<blockquote>'.e($seg['text']).'</blockquote>';

                continue;
            }

            $headingBody = preg_match(
                '/^([A-Z0-9][A-Z0-9 &\/\'\-]{1,60}?)\s+(?=[A-Z][a-z])/u',
                $seg['text'],
                $m
            );
            // No trailing sentence in this segment — the whole thing may still be a
            // bare heading (e.g. "🔥 PRODUCT FEATURES" immediately followed by its
            // own ✅ bullet marker, with nothing left over for this segment).
            $headingOnly = ! $headingBody && preg_match('/^[A-Z0-9][A-Z0-9 &\/\'\-]{1,60}$/u', $seg['text']);

            if ($headingBody || $headingOnly) {
                $html .= $this->flushBullets($bullets);
                $heading = mb_convert_case(trim($headingBody ? $m[1] : $seg['text']), MB_CASE_TITLE, 'UTF-8');
                $body = $headingBody ? trim(mb_substr($seg['text'], mb_strlen($m[0]))) : '';
                $html .= '<h3>'.e($heading).'</h3>';
                if ($body !== '') {
                    $html .= '<p>'.e($body).'</p>';
                }

                continue;
            }

            $bullets[] = $this->bulletHtml($seg['text']);
        }
        $html .= $this->flushBullets($bullets);

        return $html !== '' ? $html : null;
    }

    /** @param list<string> $bullets */
    private function flushBullets(array &$bullets): string
    {
        if ($bullets === []) {
            return '';
        }
        $out = '<ul>'.implode('', array_map(fn ($li) => '<li>'.$li.'</li>', $bullets)).'</ul>';
        $bullets = [];

        return $out;
    }

    private function bulletHtml(string $text): string
    {
        if (preg_match('/^([A-Z][A-Za-z ]{1,24}):\s*(.+)$/u', $text, $m)) {
            return '<strong>'.e(trim($m[1])).':</strong> '.e(trim($m[2]));
        }

        return e($text);
    }

    /** Bold a short opening hook sentence, the way a professionally written listing leads. */
    private function leadParagraph(string $text): string
    {
        if (preg_match('/^(.{1,90}?[.!])\s+(.+)$/us', $text, $m)) {
            return '<strong>'.e($m[1]).'</strong> '.e($m[2]);
        }

        return e($text);
    }
}
