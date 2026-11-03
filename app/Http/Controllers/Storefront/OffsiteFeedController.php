<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Marketing\OffsiteAdsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Product catalog feed for Offsite Ads, in Google Merchant RSS format — which
 * Meta Commerce Manager (Facebook/Instagram), Pinterest and Microsoft (Bing)
 * also accept. Ad platforms fetch this on a schedule and build ads from it.
 * Every link carries ?oa=<channel> so a click is attributed back to the seller.
 */
class OffsiteFeedController extends Controller
{
    /** Google product taxonomy: Vehicles & Parts > Vehicle Parts & Accessories. */
    private const GOOGLE_CATEGORY = 5613;

    public function show(Request $request, string $channel, OffsiteAdsService $offsite)
    {
        abort_unless($offsite->enabled() && array_key_exists($channel, config('marketplace.offsite_ads.channels', [])), 404);

        $key = config('marketplace.offsite_ads.feed_key');
        abort_if($key && ! hash_equals((string) $key, (string) $request->query('key')), 403);

        $xml = Cache::remember("offsite_feed.$channel", 1800, fn () => $this->build($channel, $offsite));

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    private function build(string $channel, OffsiteAdsService $offsite): string
    {
        $x = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $money = fn ($v) => number_format((float) $v, 2, '.', '').' USD';

        $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel>'
            .'<title>'.$x(config('app.name')).'</title>'
            .'<link>'.$x(url('/')).'</link>'
            .'<description>'.$x(config('app.name').' auto parts catalog').'</description>';

        Product::listed()
            ->where('price', '>', 0)
            ->with(['brand', 'category.parent', 'seller.sellerProfile', 'media'])
            ->chunkById(200, function ($products) use (&$out, $x, $money, $channel, $offsite) {
                foreach ($products as $p) {
                    // Only advertise participating sellers, and only listings an ad can show (needs a photo).
                    if (! $offsite->participates($p->seller?->sellerProfile) || ! $p->hasMedia('images')) {
                        continue;
                    }

                    $images = $p->getMedia('images');
                    $price = (float) $p->price;
                    $effective = $p->effectivePrice();
                    $inStock = $p->hasVariants()
                        ? $p->activeVariants()->where('quantity', '>', 0)->exists()
                        : $p->quantity > 0;
                    $type = $p->category
                        ? trim(($p->category->parent ? $p->category->parent->name.' > ' : '').$p->category->name)
                        : null;

                    $out .= '<item>'
                        .'<g:id>PM-'.$p->id.'</g:id>'
                        .'<g:title>'.$x(Str::limit($p->title, 150, '')).'</g:title>'
                        .'<g:description>'.$x(Str::limit(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $p->description), ENT_QUOTES | ENT_HTML5))) ?: $p->title, 4900)).'</g:description>'
                        .'<g:link>'.$x(route('products.show', $p->slug).'?'.http_build_query(['oa' => $channel, 'utm_source' => $channel, 'utm_medium' => 'offsite_ads'])).'</g:link>'
                        .'<g:image_link>'.$x(url($images->first()->getUrl())).'</g:image_link>';
                    foreach ($images->slice(1, 9) as $img) {
                        $out .= '<g:additional_image_link>'.$x(url($img->getUrl())).'</g:additional_image_link>';
                    }
                    $out .= '<g:availability>'.($inStock ? 'in_stock' : 'out_of_stock').'</g:availability>'
                        .'<g:price>'.$money($price).'</g:price>'
                        .($effective < $price ? '<g:sale_price>'.$money($effective).'</g:sale_price>' : '')
                        .'<g:condition>'.($p->condition === 'refurbished' ? 'refurbished' : ($p->condition === 'used' ? 'used' : 'new')).'</g:condition>'
                        .'<g:google_product_category>'.self::GOOGLE_CATEGORY.'</g:google_product_category>'
                        .($type ? '<g:product_type>'.$x($type).'</g:product_type>' : '')
                        .($p->brand ? '<g:brand>'.$x($p->brand->name).'</g:brand>' : '')
                        .($p->part_number ? '<g:mpn>'.$x($p->part_number).'</g:mpn>' : '')
                        .(! $p->brand || ! $p->part_number ? '<g:identifier_exists>no</g:identifier_exists>' : '')
                        .'<g:custom_label_0>'.$x($p->seller?->sellerProfile?->business_name).'</g:custom_label_0>'
                        .'</item>';
                }
            });

        return $out.'</channel></rss>';
    }
}
