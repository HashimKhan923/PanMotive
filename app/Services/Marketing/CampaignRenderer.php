<?php

namespace App\Services\Marketing;

use App\Models\Coupon;
use App\Models\MarketingCampaign;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns a block design into the email people receive: table-based HTML with inline styles
 * (what Gmail, Outlook and phone apps understand), personalised for each person, with every
 * link tracked. Prices and stock are read at send time, so an email never shows a stale price.
 *
 * Modes: "send" (real email, tracked links), "preview" (builder and test sends: plain links),
 * "report" (the sent email with a click count on each block).
 */
class CampaignRenderer
{
    /** @var array<int, Product|null> */
    private array $products = [];

    public function __construct(private MarketingConsent $consent) {}

    /**
     * @param  array<string, int>  $clickCounts  link key => human clicks (report mode)
     * @return array{subject: string, preview: string, html: string}
     */
    public function render(MarketingCampaign $campaign, ?User $user, ?string $token, string $mode = 'send', array $clickCounts = []): array
    {
        $profile = SellerProfile::where('user_id', $campaign->seller_id)->first();
        $store = $profile?->business_name ?? 'our store';
        $vehicle = $user?->active_vehicle['label'] ?? null;
        $vars = [
            '{first_name}' => $user ? (Str::before(trim((string) $user->name), ' ') ?: 'there') : 'there',
            '{store_name}' => $store,
            '{vehicle}' => $vehicle ?: 'vehicle',
        ];
        $fill = fn (string $s) => strtr($s, $vars);
        $brand = CampaignDesign::brand($profile?->email_brand);
        $storeUrl = $profile?->slug ? route('sellers.show', $profile) : config('app.url');

        $link = function (string $key, string $url) use ($mode, $token): string {
            if ($mode !== 'send' || ! $token) {
                return $url;
            }

            return route('m.click', ['token' => $token, 'k' => $key, 'u' => $url, 's' => self::sign($token, $key, $url)]);
        };

        $blocks = [];
        foreach ($campaign->design ?? [] as $block) {
            $html = $this->block($block, $campaign->seller_id, $user, $fill, $link, $brand, $storeUrl);
            if ($html === '') {
                continue;
            }
            if ($mode === 'report') {
                $n = collect($clickCounts)->filter(fn ($c, $k) => $k === $block['id'] || Str::startsWith($k, $block['id'].':'))->sum();
                $html = '<div style="position:relative;outline:2px dashed '.($n ? '#E51B2B' : '#D9DDE3').';outline-offset:4px;border-radius:6px;">'
                    .'<span style="position:absolute;top:-12px;right:-6px;z-index:2;background:'.($n ? '#E51B2B' : '#8A919C').';color:#fff;font:600 12px/1 Arial,sans-serif;padding:5px 8px;border-radius:999px;">'
                    .$n.' '.Str::plural('click', $n).'</span>'.$html.'</div>';
            }
            $blocks[] = $html;
        }

        $unsubscribe = $user && $mode === 'send' ? $this->consent->unsubscribeUrl($user, $campaign->seller_id) : '#';
        $logo = $profile?->logo_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($profile->logo_path) : null;

        $html = view('emails.designed', [
            'blocks' => $blocks,
            'brand' => $brand,
            'store' => $store,
            'storeUrl' => $link('header', $storeUrl),
            'logo' => $logo,
            'preview' => $fill((string) $campaign->preview),
            'unsubscribeUrl' => $unsubscribe,
            'preferencesUrl' => route('marketing.preferences'),
            'address' => config('marketplace.marketing.postal_address'),
            'openPixel' => $mode === 'send' && $token ? route('m.open', $token) : null,
        ])->render();

        return [
            'subject' => $fill((string) ($campaign->subject ?: "News from {$store}")),
            'preview' => $fill((string) $campaign->preview),
            'html' => $html,
        ];
    }

    public static function sign(string $token, string $key, string $url): string
    {
        return substr(hash_hmac('sha256', $token.'|'.$key.'|'.$url, (string) config('app.key')), 0, 20);
    }

    private function block(array $block, int $sellerId, ?User $user, \Closure $fill, \Closure $link, array $brand, string $storeUrl): string
    {
        $p = $block['props'];
        $id = $block['id'];
        $e = fn ($s) => e($fill((string) $s));
        $para = fn ($s) => nl2br($e($s));
        $color = $brand['color'];
        $radius = ['rounded' => '8px', 'pill' => '999px', 'square' => '0'][$brand['button']];
        $btn = fn (string $label, string $url, string $align = 'center') => '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 '.($align === 'center' ? 'auto' : ($align === 'right' ? '0 0 0 auto' : '0')).';"><tr><td style="background:'.$color.';border-radius:'.$radius.';">'
            .'<a href="'.e($url).'" style="display:inline-block;padding:13px 28px;font:600 15px/1.2 Arial,Helvetica,sans-serif;color:#ffffff;text-decoration:none;border-radius:'.$radius.';">'.$e($label).'</a></td></tr></table>';
        $target = function (array $p, string $key) use ($storeUrl, $link, $id): ?string {
            return match ($p['link'] ?? 'store') {
                'none' => null,
                'custom' => $p['custom_url'] ? $link($id, $p['custom_url']) : null,
                'product' => ($prod = $this->product($p['product_id'] ?? null)) ? $link($id.':p'.$prod->id, route('products.show', $prod->slug)) : null,
                default => $link($id, $storeUrl),
            };
        };

        return match ($block['type']) {
            'heading' => '<h2 style="margin:0;font:700 '.($p['size'] === 'medium' ? '20px/1.3' : '26px/1.25').' Arial,Helvetica,sans-serif;color:#15191E;text-align:'.$p['align'].';">'.$e($p['text']).'</h2>',
            'text' => '<p style="margin:0;font:400 16px/1.6 Arial,Helvetica,sans-serif;color:#3D444E;text-align:'.$p['align'].';">'.$para($p['text']).'</p>',
            'image' => $p['url'] === '' ? '' : (($to = $target($p, 'image')) ? '<a href="'.e($to).'">' : '')
                .'<img src="'.e($p['url']).'" alt="'.$e($p['alt']).'" width="536" style="display:block;width:100%;max-width:536px;height:auto;border:0;border-radius:10px;">'.($to ? '</a>' : ''),
            'button' => ($to = $target($p, 'button')) ? $btn($p['label'], $to, $p['align']) : '',
            'product' => ($prod = $this->product($p['product_id'])) ? $this->productCard($prod, $p['button_label'], $link, $id, $btn, (bool) $p['show_description'], true) : '',
            'products' => $this->grid(collect($p['product_ids'])->map(fn ($pid) => $this->product($pid))->filter(), (int) $p['columns'], $p['button_label'], $link, $id, $btn),
            'smart_products' => $this->smart($p, $sellerId, $user, $fill, $link, $id, $btn),
            'coupon' => $this->coupon($p, $sellerId, $fill, $link, $id, $btn, $storeUrl, $color),
            'columns' => '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
                .'<td class="pm-col" width="50%" valign="top" style="padding-right:10px;font:400 15px/1.6 Arial,Helvetica,sans-serif;color:#3D444E;">'.$para($p['left']).'</td>'
                .'<td class="pm-col" width="50%" valign="top" style="padding-left:10px;font:400 15px/1.6 Arial,Helvetica,sans-serif;color:#3D444E;">'.$para($p['right']).'</td></tr></table>',
            'video' => ($vid = CampaignDesign::youtubeId($p['url'])) ? '<a href="'.e($link($id, $p['url'])).'" style="display:block;text-decoration:none;">'
                .'<img src="https://img.youtube.com/vi/'.$vid.'/hqdefault.jpg" alt="Play video" width="536" style="display:block;width:100%;max-width:536px;height:auto;border:0;border-radius:10px;">'
                .'<p style="margin:10px 0 0;font:600 15px/1.4 Arial,Helvetica,sans-serif;color:#15191E;">▶ '.$e($p['caption']).'</p></a>' : '',
            'social' => $this->social($p, $link, $id),
            'divider' => '<hr style="border:0;border-top:1px solid #E6E9ED;margin:0;">',
            'spacer' => '<div style="height:'.(int) $p['size'].'px;line-height:'.(int) $p['size'].'px;font-size:1px;">&nbsp;</div>',
            default => '',
        };
    }

    private function product(?int $id): ?Product
    {
        if (! $id) {
            return null;
        }
        if (! array_key_exists($id, $this->products)) {
            $this->products[$id] = Product::listed()->with('media')->find($id);
        }

        return $this->products[$id];
    }

    private function image(Product $p): ?string
    {
        return $p->getFirstMedia('images')?->getUrl() ?: null;
    }

    private function productCard(Product $p, string $label, \Closure $link, string $id, \Closure $btn, bool $description = false, bool $wide = false): string
    {
        $url = $link($id.':p'.$p->id, route('products.show', $p->slug));
        $img = $this->image($p);
        $price = '$'.number_format($p->effectivePrice(), 2);
        $was = $p->effectivePrice() < (float) $p->price ? ' <span style="color:#8A919C;text-decoration:line-through;font-weight:400;">$'.number_format((float) $p->price, 2).'</span>' : '';
        $stock = (int) $p->quantity > 0 ? '' : '<p style="margin:4px 0 0;font:600 13px Arial,sans-serif;color:#BB1220;">Out of stock</p>';

        if ($wide) {
            return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #E6E9ED;border-radius:12px;"><tr>'
                .'<td class="pm-col" width="45%" valign="top" style="padding:16px;">'.($img ? '<a href="'.e($url).'"><img src="'.e($img).'" alt="'.e($p->title).'" width="220" style="display:block;width:100%;height:auto;border:0;border-radius:8px;"></a>' : '').'</td>'
                .'<td class="pm-col" valign="middle" style="padding:16px 16px 16px 4px;">'
                .'<p style="margin:0;font:700 17px/1.35 Arial,Helvetica,sans-serif;color:#15191E;">'.e($p->title).'</p>'
                .($description && $p->bullet_points ? '<p style="margin:6px 0 0;font:400 14px/1.5 Arial,sans-serif;color:#5E6672;">'.e(Str::limit(strip_tags(is_array($p->bullet_points) ? implode(' · ', $p->bullet_points) : (string) $p->bullet_points), 140)).'</p>' : '')
                .'<p style="margin:10px 0 14px;font:700 18px Arial,sans-serif;color:#15191E;">'.$price.$was.'</p>'.$stock
                .$btn($label, $url, 'left').'</td></tr></table>';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:0 0 4px;">'
            .($img ? '<a href="'.e($url).'"><img src="'.e($img).'" alt="'.e($p->title).'" width="250" style="display:block;width:100%;max-width:250px;height:auto;border:0;border-radius:8px;"></a>' : '')
            .'<p style="margin:10px 0 0;font:600 14px/1.4 Arial,Helvetica,sans-serif;color:#15191E;">'.e(Str::limit($p->title, 70)).'</p>'
            .'<p style="margin:4px 0 10px;font:700 16px Arial,sans-serif;color:#15191E;">'.$price.$was.'</p>'.$stock
            .$btn($label, $url).'</td></tr></table>';
    }

    private function grid(Collection $products, int $columns, string $label, \Closure $link, string $id, \Closure $btn): string
    {
        if ($products->isEmpty()) {
            return '';
        }
        $columns = max(1, min(3, $columns));
        $rows = '';
        foreach ($products->values()->chunk($columns) as $row) {
            $rows .= '<tr>';
            foreach ($row as $p) {
                $rows .= '<td class="pm-col" width="'.floor(100 / $columns).'%" valign="top" style="padding:8px;">'.$this->productCard($p, $label, $link, $id, $btn).'</td>';
            }
            for ($i = $row->count(); $i < $columns; $i++) {
                $rows .= '<td class="pm-col" width="'.floor(100 / $columns).'%" style="padding:8px;"></td>';
            }
            $rows .= '</tr>';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'.$rows.'</table>';
    }

    private function smart(array $p, int $sellerId, ?User $user, \Closure $fill, \Closure $link, string $id, \Closure $btn): string
    {
        $vehicleId = $user?->active_vehicle['id'] ?? null;
        $base = fn () => Product::listed()->where('seller_id', $sellerId)->where('quantity', '>', 0)->with('media');

        $products = match ($p['source']) {
            'fits_vehicle' => $vehicleId ? $base()->whereHas('fitments', fn ($q) => $q->where('vehicles.id', $vehicleId))->latest('id')->take($p['count'])->get() : collect(),
            'new_arrivals' => $base()->latest('id')->take($p['count'])->get(),
            default => collect(),
        };
        // Bestsellers, and the fallback when we don't know their vehicle or nothing fits it.
        if ($products->isEmpty()) {
            $top = OrderItem::where('seller_id', $sellerId)->where('created_at', '>=', now()->subDays(120))
                ->selectRaw('product_id, SUM(quantity) as sold')->groupBy('product_id')->orderByDesc('sold')->limit(30)->pluck('product_id');
            $products = $base()->whereIn('id', $top)->get()->sortBy(fn ($x) => $top->search($x->id))->take($p['count']);
            if ($products->count() < $p['count']) {
                $products = $products->concat($base()->whereNotIn('id', $products->pluck('id'))->latest('id')->take($p['count'] - $products->count())->get());
            }
        }
        if ($products->isEmpty()) {
            return '';
        }
        $title = trim($fill((string) $p['title']));

        return ($title !== '' ? '<h3 style="margin:0 0 6px;font:700 19px/1.3 Arial,Helvetica,sans-serif;color:#15191E;">'.e($title).'</h3>' : '')
            .$this->grid($products, (int) $p['columns'], 'Shop now', $link, $id, $btn);
    }

    private function coupon(array $p, int $sellerId, \Closure $fill, \Closure $link, string $id, \Closure $btn, string $storeUrl, string $color): string
    {
        $c = $p['coupon_id'] ? Coupon::where('seller_id', $sellerId)->whereNull('user_id')->find($p['coupon_id']) : null;
        if (! $c || ! $c->is_active || ($c->expires_at && $c->expires_at->isPast())) {
            return '';
        }
        $off = $c->type === 'percentage' ? rtrim(rtrim(number_format((float) $c->value, 2), '0'), '.').'% off' : '$'.number_format((float) $c->value, 2).' off';

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:2px dashed '.$color.';border-radius:14px;"><tr><td align="center" style="padding:24px 20px;">'
            .'<p style="margin:0;font:600 15px/1.4 Arial,sans-serif;color:#5E6672;">'.e($fill((string) $p['headline'])).'</p>'
            .'<p style="margin:8px 0 4px;font:800 30px/1.2 Arial,Helvetica,sans-serif;color:#15191E;">'.e($off).'</p>'
            .'<p style="margin:0 0 14px;font:400 14px Arial,sans-serif;color:#5E6672;">with code <strong style="font:700 17px \'Courier New\',monospace;letter-spacing:2px;color:'.$color.';">'.e($c->code).'</strong>'
            .($c->min_order_total ? ' on orders over $'.number_format((float) $c->min_order_total, 0) : '')
            .($c->expires_at ? ' · ends '.$c->expires_at->format('M j') : '').'</p>'
            .$btn($p['button_label'], $link($id, $storeUrl)).'</td></tr></table>';
    }

    private function social(array $p, \Closure $link, string $id): string
    {
        $links = collect(['facebook' => 'Facebook', 'instagram' => 'Instagram', 'youtube' => 'YouTube', 'tiktok' => 'TikTok'])
            ->filter(fn ($label, $k) => $p[$k] !== '')
            ->map(fn ($label, $k) => '<a href="'.e($link($id.':'.$k, $p[$k])).'" style="display:inline-block;margin:0 6px;padding:8px 14px;border:1px solid #D9DDE3;border-radius:999px;font:600 13px Arial,sans-serif;color:#15191E;text-decoration:none;">'.$label.'</a>');

        return $links->isEmpty() ? '' : '<p style="margin:0;text-align:center;">'.$links->implode('').'</p>';
    }
}
