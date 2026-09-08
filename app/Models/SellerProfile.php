<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerProfile extends Model
{
    protected $fillable = [
        'user_id', 'business_name', 'business_type', 'ein_encrypted',
        'stripe_account_id', 'stripe_onboarding_complete', 'status',
        'kyc_status', 'commission_rate_override', 'rating', 'rating_count',
        'shipping_settings',
    ];

    protected $casts = [
        'ein_encrypted' => 'encrypted',
        'shipping_settings' => 'array',
        'stripe_onboarding_complete' => 'boolean',
        'commission_rate_override' => 'decimal:2',
        'rating' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function commissionRate(): float
    {
        return $this->commission_rate_override ?? (float) config('marketplace.default_commission_rate');
    }
}
