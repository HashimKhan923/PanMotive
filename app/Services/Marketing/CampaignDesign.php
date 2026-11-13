<?php

namespace App\Services\Marketing;

use App\Models\Coupon;
use App\Models\Product;
use Illuminate\Support\Str;

/**
 * The building blocks of a designed campaign email, and the rules for them.
 *
 * A design is a list of blocks: [{id, type, props}]. Sellers can only arrange these blocks and
 * fill their settings: they never write HTML. That is what keeps every email readable in Gmail,
 * Outlook and on phones, and stops anyone sending broken or unsafe code from our domain.
 */
class CampaignDesign
{
    /** @return array<string, array{label: string, icon: string, props: array<string, mixed>}> */
    public static function types(): array
    {
        return [
            'heading' => ['label' => 'Heading', 'icon' => 'list', 'props' => ['text' => 'Big news from {store_name}', 'align' => 'left', 'size' => 'large']],
            'text' => ['label' => 'Text', 'icon' => 'message', 'props' => ['text' => "Hi {first_name},\n\nWrite your message here.", 'align' => 'left']],
            'image' => ['label' => 'Image', 'icon' => 'image', 'props' => ['url' => '', 'alt' => '', 'link' => 'store', 'custom_url' => '']],
            'button' => ['label' => 'Button', 'icon' => 'arrow-right', 'props' => ['label' => 'Shop now', 'link' => 'store', 'product_id' => null, 'custom_url' => '', 'align' => 'center']],
            'product' => ['label' => 'Product', 'icon' => 'box', 'props' => ['product_id' => null, 'button_label' => 'Shop now', 'show_description' => true]],
            'products' => ['label' => 'Product grid', 'icon' => 'grid', 'props' => ['product_ids' => [], 'columns' => 2, 'button_label' => 'Shop now']],
            'smart_products' => ['label' => 'Smart products', 'icon' => 'sparkles', 'props' => ['source' => 'fits_vehicle', 'count' => 4, 'columns' => 2, 'title' => 'Picked for your {vehicle}']],
            'coupon' => ['label' => 'Coupon', 'icon' => 'tag', 'props' => ['coupon_id' => null, 'headline' => 'A little something for you', 'button_label' => 'Shop the sale']],
            'columns' => ['label' => 'Two columns', 'icon' => 'grid', 'props' => ['left' => 'Fast shipping on every order.', 'right' => 'Covered by the Panmotive Fit Guarantee.']],
            'video' => ['label' => 'Video', 'icon' => 'external', 'props' => ['url' => '', 'caption' => 'Watch how to install it']],
            'social' => ['label' => 'Social links', 'icon' => 'users', 'props' => ['facebook' => '', 'instagram' => '', 'youtube' => '', 'tiktok' => '']],
            'divider' => ['label' => 'Divider', 'icon' => 'filter', 'props' => []],
            'spacer' => ['label' => 'Spacer', 'icon' => 'grip', 'props' => ['size' => 24]],
        ];
    }

    public const SMART_SOURCES = [
        'fits_vehicle' => 'Parts that fit their vehicle',
        'bestsellers' => 'My bestsellers',
        'new_arrivals' => 'My newest listings',
    ];

    public const PLACEHOLDERS = ['{first_name}' => 'Their first name', '{store_name}' => 'Your store name', '{vehicle}' => 'Their vehicle (e.g. 2018 Toyota Camry)'];

    public static function block(string $type, array $props = []): array
    {
        return ['id' => 'b'.Str::lower(Str::random(8)), 'type' => $type, 'props' => array_merge(self::types()[$type]['props'], $props)];
    }

    /** @return array<string, array{name: string, about: string, design: list<array>}> */
    public static function starters(): array
    {
        $b = fn (string $t, array $p = []) => self::block($t, $p);

        return [
            'new_arrivals' => ['name' => 'New arrivals', 'about' => 'Show off your newest parts', 'design' => [
                $b('heading', ['text' => 'Just landed at {store_name}']),
                $b('text', ['text' => "Hi {first_name},\n\nFresh parts just hit our shelves. Here’s what’s new this week."]),
                $b('smart_products', ['source' => 'new_arrivals', 'count' => 4, 'title' => 'New this week']),
                $b('button', ['label' => 'See everything new']),
            ]],
            'sale' => ['name' => 'Sale', 'about' => 'A coupon plus your best deals', 'design' => [
                $b('heading', ['text' => 'Sale on now, {first_name}', 'align' => 'center']),
                $b('text', ['text' => 'For a few days only, save on parts for your ride.', 'align' => 'center']),
                $b('coupon'),
                $b('smart_products', ['source' => 'bestsellers', 'count' => 4, 'title' => 'Our bestsellers']),
            ]],
            'restock' => ['name' => 'Back in stock', 'about' => 'One part is back', 'design' => [
                $b('heading', ['text' => 'It’s back in stock']),
                $b('text', ['text' => "Hi {first_name},\n\nOne of our most asked-for parts is available again. Stock is limited."]),
                $b('product'),
            ]],
            'fits_your_car' => ['name' => 'Parts for their car', 'about' => 'Each buyer sees parts for their own vehicle', 'design' => [
                $b('heading', ['text' => 'Parts for your {vehicle}']),
                $b('text', ['text' => "Hi {first_name},\n\nWe picked these parts because they fit your {vehicle}. Every one is covered by the Panmotive Fit Guarantee."]),
                $b('smart_products', ['source' => 'fits_vehicle', 'count' => 6, 'columns' => 3, 'title' => '']),
                $b('button', ['label' => 'Shop all parts']),
            ]],
            'seasonal' => ['name' => 'Seasonal', 'about' => 'Winter or summer checklist', 'design' => [
                $b('heading', ['text' => 'Get your ride ready for the season']),
                $b('columns', ['left' => 'Batteries, wipers and lights: the parts that fail first when the weather turns.', 'right' => 'Order now and fit them this weekend, before the rush.']),
                $b('smart_products', ['source' => 'bestsellers', 'count' => 4, 'title' => 'Seasonal must-haves']),
            ]],
            'thank_you' => ['name' => 'Thank you', 'about' => 'Thank your customers', 'design' => [
                $b('heading', ['text' => 'Thank you, {first_name}!', 'align' => 'center']),
                $b('text', ['text' => 'Thanks for shopping with {store_name}. We hope your parts fit perfectly. Need anything else? Just reply to this email.', 'align' => 'center']),
                $b('divider'),
                $b('smart_products', ['source' => 'fits_vehicle', 'count' => 2, 'title' => 'You might also need']),
            ]],
            'blank' => ['name' => 'Start from scratch', 'about' => 'An empty email', 'design' => [
                $b('heading'), $b('text'),
            ]],
        ];
    }

    /** Brand settings a seller can set once for all their emails. */
    public static function brand(?array $saved): array
    {
        $b = array_merge(['color' => '#E51B2B', 'button' => 'rounded', 'header' => 'dark'], $saved ?? []);
        $b['color'] = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $b['color']) ? strtoupper($b['color']) : '#E51B2B';
        $b['button'] = in_array($b['button'], ['rounded', 'pill', 'square'], true) ? $b['button'] : 'rounded';
        $b['header'] = in_array($b['header'], ['dark', 'light'], true) ? $b['header'] : 'dark';

        return $b;
    }

    /**
     * Clean a design posted from the builder: known block types only, known settings only,
     * text trimmed to sensible lengths, and only the seller's own products and coupons.
     */
    public static function sanitize(array $blocks, int $sellerId): array
    {
        $types = self::types();
        $productIds = Product::where('seller_id', $sellerId)->pluck('id')->all();
        $couponIds = Coupon::where('seller_id', $sellerId)->whereNull('user_id')->pluck('id')->all();
        $clean = [];

        foreach (array_slice($blocks, 0, 40) as $block) {
            $type = $block['type'] ?? null;
            if (! is_string($type) || ! isset($types[$type])) {
                continue;
            }
            $props = [];
            foreach ($types[$type]['props'] as $key => $default) {
                $value = $block['props'][$key] ?? $default;
                $props[$key] = match (true) {
                    in_array($key, ['product_id', 'coupon_id'], true) => self::ownedId($value, $key === 'product_id' ? $productIds : $couponIds),
                    $key === 'product_ids' => array_values(array_slice(array_intersect(array_map('intval', (array) $value), $productIds), 0, 9)),
                    in_array($key, ['columns', 'count', 'size'], true) => self::clampInt($key, $value),
                    in_array($key, ['show_description'], true) => (bool) $value,
                    $key === 'align' => in_array($value, ['left', 'center', 'right'], true) ? $value : 'left',
                    $key === 'size' => $value,
                    $key === 'source' => array_key_exists($value, self::SMART_SOURCES) ? $value : 'bestsellers',
                    $key === 'link' => in_array($value, ['store', 'product', 'custom', 'none'], true) ? $value : 'store',
                    in_array($key, ['url', 'custom_url', 'facebook', 'instagram', 'youtube', 'tiktok'], true) => self::safeUrl($value),
                    default => Str::limit(trim((string) $value), in_array($key, ['text', 'left', 'right'], true) ? 2000 : 160, ''),
                };
            }
            if ($type === 'heading') {
                $props['size'] = in_array($block['props']['size'] ?? 'large', ['large', 'medium'], true) ? $block['props']['size'] : 'large';
            }
            $id = preg_match('/^b[a-z0-9]{4,12}$/', (string) ($block['id'] ?? '')) ? $block['id'] : 'b'.Str::lower(Str::random(8));
            $clean[] = ['id' => $id, 'type' => $type, 'props' => $props];
        }

        return $clean;
    }

    private static function ownedId(mixed $value, array $allowed): ?int
    {
        return in_array((int) $value, $allowed, true) ? (int) $value : null;
    }

    private static function clampInt(string $key, mixed $value): int
    {
        return match ($key) {
            'columns' => in_array((int) $value, [1, 2, 3], true) ? (int) $value : 2,
            'count' => max(1, min(9, (int) $value)),
            'size' => max(8, min(80, (int) $value)),
        };
    }

    /** http(s) links only. Images we host are on our own domain anyway. */
    public static function safeUrl(mixed $value): string
    {
        $url = trim((string) $value);

        return $url !== '' && preg_match('~^https?://[^\s<>"]+$~i', $url) && strlen($url) <= 500 ? $url : '';
    }

    /**
     * Problems to fix (or think about) before sending.
     *
     * @return list<array{level: string, text: string}>
     */
    public static function quality(?string $subject, ?string $preview, array $blocks, int $sellerId): array
    {
        $issues = [];
        $subject = trim((string) $subject);

        if ($subject === '') {
            $issues[] = ['level' => 'error', 'text' => 'Add a subject line.'];
        } elseif (mb_strlen($subject) > 60) {
            $issues[] = ['level' => 'warning', 'text' => 'The subject is '.mb_strlen($subject).' characters. Phones cut it off after about 40–60.'];
        }
        if ($subject !== '' && preg_match_all('/[A-Z]/', $subject) >= 6 && mb_strtoupper($subject) === $subject) {
            $issues[] = ['level' => 'warning', 'text' => 'The subject is all capital letters, which spam filters dislike.'];
        }
        if (preg_match('/(!!+|\$\$+|100% free|act now|risk[- ]free|click here|winner|cash bonus)/i', $subject.' '.collect($blocks)->pluck('props.text')->implode(' '))) {
            $issues[] = ['level' => 'warning', 'text' => 'Some words (like “act now”, “click here” or “!!”) can send emails to spam. Try calmer wording.'];
        }
        if (trim((string) $preview) === '') {
            $issues[] = ['level' => 'tip', 'text' => 'Add preview text. It shows next to the subject and gets more people to open.'];
        }
        if ($blocks === []) {
            $issues[] = ['level' => 'error', 'text' => 'The email is empty. Drag in some blocks.'];

            return $issues;
        }

        $types = collect($blocks)->pluck('type');
        if (! $types->intersect(['button', 'product', 'products', 'smart_products', 'coupon'])->count()) {
            $issues[] = ['level' => 'warning', 'text' => 'There’s no button or product, so readers have nothing to click.'];
        }
        foreach ($blocks as $i => $block) {
            $p = $block['props'];
            $n = $i + 1;
            match ($block['type']) {
                'image' => $p['url'] === '' ? $issues[] = ['level' => 'error', 'text' => "Block {$n}: choose or upload an image."]
                    : ($p['alt'] === '' ? $issues[] = ['level' => 'tip', 'text' => "Block {$n}: add a short image description. Many inboxes hide images until the reader allows them."] : null),
                'product' => $p['product_id'] === null ? $issues[] = ['level' => 'error', 'text' => "Block {$n}: pick a product."] : self::productIssue($p['product_id'], $n, $issues),
                'products' => $p['product_ids'] === [] ? $issues[] = ['level' => 'error', 'text' => "Block {$n}: pick at least one product."] : null,
                'coupon' => $p['coupon_id'] === null ? $issues[] = ['level' => 'error', 'text' => "Block {$n}: pick a coupon."] : self::couponIssue($p['coupon_id'], $n, $issues),
                'button' => ($p['link'] === 'custom' && $p['custom_url'] === '') || ($p['link'] === 'product' && ! $p['product_id'])
                    ? $issues[] = ['level' => 'error', 'text' => "Block {$n}: the button has no link."] : null,
                'video' => $p['url'] === '' ? $issues[] = ['level' => 'error', 'text' => "Block {$n}: paste a YouTube link."] : null,
                default => null,
            };
        }
        if ($types->filter(fn ($t) => $t === 'image')->count() > 6) {
            $issues[] = ['level' => 'warning', 'text' => 'Lots of images. Emails that are mostly pictures are more likely to land in spam.'];
        }

        return $issues;
    }

    private static function productIssue(int $id, int $n, array &$issues): void
    {
        $p = Product::find($id);
        if (! $p || $p->status !== 'active') {
            $issues[] = ['level' => 'error', 'text' => "Block {$n}: that product isn’t live on your store."];
        } elseif ((int) $p->quantity <= 0) {
            $issues[] = ['level' => 'warning', 'text' => "Block {$n}: “{$p->title}” is out of stock."];
        }
    }

    private static function couponIssue(int $id, int $n, array &$issues): void
    {
        $c = Coupon::find($id);
        if (! $c || ! $c->is_active || ($c->expires_at && $c->expires_at->isPast())) {
            $issues[] = ['level' => 'error', 'text' => "Block {$n}: that coupon isn’t active."];
        }
    }

    /** The YouTube video id from a link, for the thumbnail. */
    public static function youtubeId(string $url): ?string
    {
        return preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|shorts/|embed/))([A-Za-z0-9_-]{11})~', $url, $m) ? $m[1] : null;
    }
}
