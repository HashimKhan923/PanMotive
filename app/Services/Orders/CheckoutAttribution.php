<?php

namespace App\Services\Orders;

use App\Models\Order;
use Illuminate\Http\Request;

/**
 * Credits a new order to whatever brought the buyer in: an email campaign, a promo link, an ad,
 * an affiliate, an automation, or one of our Offsite Ads. Website orders only — these are cookies.
 */
class CheckoutAttribution
{
    public const COOKIES = ['pm_campaign', 'pm_ref', 'pm_ad', 'pm_aff', 'pm_automation'];

    /** @return array<string, ?string> */
    public static function cookiesFrom(Request $request): array
    {
        $out = [];
        foreach ([...self::COOKIES, \App\Services\Marketing\OffsiteAdsService::COOKIE] as $name) {
            $out[$name] = $request->cookie($name);
        }

        return $out;
    }

    /** @param array<string, ?string> $cookies the marketing cookies the browser sent (COOKIES keys) */
    public function attribute(Order $order, array $cookies): void
    {
        $order->loadMissing('items');
        $sellerTotals = $order->items->groupBy('seller_id')->map->sum('line_total');

        if ($token = ($cookies['pm_campaign'] ?? null)) {
            $recipient = \App\Models\CampaignRecipient::with('campaign')->where('token', $token)->first();
            $sellerId = $recipient?->campaign?->seller_id;
            if ($recipient && ! $recipient->converted_order_id && $sellerId && $sellerTotals->has($sellerId)) {
                $recipient->update([
                    'converted_order_id' => $order->id,
                    'conversion_value' => round($sellerTotals[$sellerId], 2),
                ]);
                \App\Models\MarketingEvent::conversion($sellerId, 'email', $recipient->marketing_campaign_id, $order->id, $sellerTotals[$sellerId]);
                app(\App\Services\Marketing\CampaignSender::class)->refreshStats($recipient->campaign);
            }
        }

        if ($code = ($cookies['pm_ref'] ?? null)) {
            $link = \App\Models\PromoLink::where('code', $code)->first();
            if ($link && ($link->seller_id && $sellerTotals->has($link->seller_id))) {
                $link->increment('orders');
                $link->increment('revenue', round($sellerTotals[$link->seller_id], 2));
                \App\Models\MarketingEvent::conversion($link->seller_id, $link->channel(), $link->id, $order->id, $sellerTotals[$link->seller_id]);
            }
        }

        if ($ad = ($cookies['pm_ad'] ?? null)) {
            [$source, $refId] = array_pad(explode(':', $ad, 2), 2, null);
            if ($source && $refId) {
                // Only the advertised seller's items count — not the whole basket or shipping.
                $ads = app(\App\Services\Seller\AdServingService::class);
                $adSeller = $ads->sellerFor($source, (int) $refId);
                if ($adSeller && $sellerTotals->has($adSeller)) {
                    $ads->attributeConversion((int) $refId, $source, round($sellerTotals[$adSeller], 2));
                    \App\Models\MarketingEvent::conversion($adSeller, 'ads', (int) $refId, $order->id, $sellerTotals[$adSeller]);
                }
            }
        }

        if ($affCode = ($cookies['pm_aff'] ?? null)) {
            $conversion = app(\App\Services\Marketing\AffiliateService::class)->attribute($order, $affCode);
            if ($conversion?->wasRecentlyCreated) {
                \App\Models\MarketingEvent::conversion($conversion->affiliate->program->seller_id, 'affiliate', $conversion->affiliate_id, $order->id, (float) $conversion->order_value);
            }
        }

        if ($autoToken = ($cookies['pm_automation'] ?? null)) {
            $send = \App\Models\AutomationSend::with('automation')->where('token', $autoToken)->first();
            $sellerId = $send?->automation?->seller_id;
            if ($send && ! $send->converted_order_id && $sellerId && $sellerTotals->has($sellerId)) {
                $send->update([
                    'converted_order_id' => $order->id,
                    'conversion_value' => round($sellerTotals[$sellerId], 2),
                ]);
                \App\Models\MarketingEvent::conversion($sellerId, 'automation', $send->marketing_automation_id, $order->id, $sellerTotals[$sellerId]);
                app(\App\Services\Marketing\MarketingAutomationService::class)->refreshStats($send->automation);
            }
        }

        // Offsite Ads: charge the ad fee to any seller whose ad brought this buyer in.
        app(\App\Services\Marketing\OffsiteAdsService::class)->attribute($order, $cookies[\App\Services\Marketing\OffsiteAdsService::COOKIE] ?? null);
    }
}
