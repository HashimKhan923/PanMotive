<?php

namespace App\Support\Html;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Allow-list HTML sanitizer for seller-authored product descriptions (Trix
 * editor output, or a CSV import's "Body (HTML)" column). Keeps structure —
 * paragraphs, bold/italic, headings, lists, links — strips everything else
 * (scripts, styles, inline event handlers, iframes, tracking junk) so the
 * result is always safe to render with {!! !!}.
 *
 * Plain text with no markup passes through untouched — the render-side
 * <x-ui.rich-text> component escapes + nl2br()'s anything that isn't HTML,
 * so legacy plain-text descriptions keep displaying correctly.
 */
class DescriptionSanitizer
{
    /** Kept, with their inner content preserved and re-nested. */
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's',
        'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'a', 'blockquote',
    ];

    /** Removed entirely, content and all — never just unwrapped. */
    private const STRIP_ENTIRELY = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'input',
        'button', 'select', 'textarea', 'svg', 'img', 'video', 'audio',
        'source', 'link', 'meta', 'noscript', 'canvas', 'picture',
    ];

    public function sanitize(?string $html, int $maxLength = 20000): ?string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return null;
        }

        $html = mb_substr($html, 0, $maxLength);

        // No markup at all — plain text. Leave it exactly as typed/imported.
        if (! preg_match('/<[a-z][^>]*>/i', $html)) {
            return $html;
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="utf-8"?><div id="__root__">'.$html.'</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NOXMLDECL
        );
        libxml_clear_errors();

        $root = $dom->getElementById('__root__');
        if (! $root) {
            return strip_tags($html) ?: null;
        }

        $this->cleanChildren($dom, $root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $dom->saveHTML($child);
        }

        $out = trim((string) $out);

        return $out !== '' ? $out : null;
    }

    private function cleanChildren(DOMDocument $dom, DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                continue;
            }

            if (! $child instanceof DOMElement) {
                $node->removeChild($child);

                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::STRIP_ENTIRELY, true)) {
                $node->removeChild($child);

                continue;
            }

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                // Unwrap: keep the inner content, drop the wrapper (e.g. Shopify's <div>/<span>).
                $this->cleanChildren($dom, $child);
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);

                continue;
            }

            $this->stripAttributes($child);
            $this->cleanChildren($dom, $child);
        }
    }

    private function stripAttributes(DOMElement $el): void
    {
        if (! $el->hasAttributes()) {
            return;
        }

        $isLink = strtolower($el->tagName) === 'a';
        $href = $isLink ? trim((string) $el->getAttribute('href')) : null;

        foreach (iterator_to_array($el->attributes) as $attr) {
            $el->removeAttribute($attr->name);
        }

        if ($isLink && $href !== '' && preg_match('#^(https?://|mailto:)#i', $href)) {
            $el->setAttribute('href', $href);
            $el->setAttribute('rel', 'noopener nofollow ugc');
            $el->setAttribute('target', '_blank');
        }
    }
}
