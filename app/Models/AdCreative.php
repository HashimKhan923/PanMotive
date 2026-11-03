<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdCreative extends Model
{
    protected $fillable = [
        'ad_campaign_id', 'product_id', 'headline', 'primary_text', 'image_path',
        'impressions', 'clicks', 'attributed_orders', 'attributed_revenue', 'is_active',
    ];

    protected $casts = ['attributed_revenue' => 'decimal:2', 'is_active' => 'boolean'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'ad_campaign_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
