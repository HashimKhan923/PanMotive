<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A dated marketing click or attributed order — the source of truth for Marketing insights. */
class MarketingEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['seller_id', 'channel', 'kind', 'ref_id', 'order_id', 'revenue', 'created_at'];

    protected $casts = ['revenue' => 'decimal:2', 'created_at' => 'datetime'];

    public const CHANNELS = [
        'email' => 'Email & SMS campaigns',
        'automation' => 'Automations',
        'promo_link' => 'Tracked links',
        'social' => 'Social posts',
        'bio' => 'Link in bio',
        'ads' => 'Sponsored ads',
        'affiliate' => 'Affiliates',
        'offsite_ads' => 'Offsite Ads',
    ];

    public static function click(?int $sellerId, string $channel, ?int $refId = null): void
    {
        if ($sellerId) {
            static::create(['seller_id' => $sellerId, 'channel' => $channel, 'kind' => 'click', 'ref_id' => $refId]);
        }
    }

    public static function conversion(int $sellerId, string $channel, ?int $refId, int $orderId, float $revenue): void
    {
        static::create([
            'seller_id' => $sellerId, 'channel' => $channel, 'kind' => 'conversion',
            'ref_id' => $refId, 'order_id' => $orderId, 'revenue' => round($revenue, 2),
        ]);
    }
}
