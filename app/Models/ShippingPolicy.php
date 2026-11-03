<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingPolicy extends Model
{
    /** Ready-made "don't ship to" sets sellers commonly need. */
    public const STATE_PRESETS = [
        'Alaska & Hawaii' => ['AK', 'HI'],
        'California (CARB-restricted parts)' => ['CA'],
        'US territories' => ['PR', 'GU', 'VI', 'AS', 'MP'],
    ];

    protected $fillable = [
        'seller_id', 'name', 'is_default', 'handling_days', 'flat_rate', 'additional_item_rate',
        'free_over', 'region_surcharges', 'excluded_states', 'no_ship_alaska_hawaii',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'no_ship_alaska_hawaii' => 'boolean',
        'flat_rate' => 'decimal:2',
        'additional_item_rate' => 'decimal:2',
        'free_over' => 'decimal:2',
        'region_surcharges' => 'array',
        'excluded_states' => 'array',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function surchargeFor(?string $state): float
    {
        $state = (string) \App\Services\Shipping\ShippingEstimateService::normalizeState($state);

        return (float) ($this->region_surcharges[$state] ?? 0);
    }

    /** free | flat | weight */
    public function method(): string
    {
        if ($this->flat_rate === null) {
            return 'weight';
        }

        return (float) $this->flat_rate <= 0 ? 'free' : 'flat';
    }

    public function excludes(?string $state): bool
    {
        $code = \App\Services\Shipping\ShippingEstimateService::normalizeState($state);

        return $code !== null && in_array($code, $this->excluded_states ?? [], true);
    }

    /** Short buyer/seller-facing description of the rate. */
    public function rateLabel(): string
    {
        return match ($this->method()) {
            'free' => 'Free shipping',
            'flat' => '$'.number_format((float) $this->flat_rate, 2).' flat'
                .($this->additional_item_rate !== null && (float) $this->additional_item_rate > 0
                    ? ' + $'.number_format((float) $this->additional_item_rate, 2).' each additional item' : ''),
            default => 'Rate by weight',
        };
    }
}
