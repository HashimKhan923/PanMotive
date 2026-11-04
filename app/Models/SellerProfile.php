<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SellerProfile extends Model
{
    protected $fillable = [
        'user_id', 'business_name', 'slug', 'business_type', 'ein_encrypted',
        'stripe_account_id', 'stripe_onboarding_complete', 'status', 'tier', 'tier_evaluated_at',
        'kyc_status', 'commission_rate_override', 'rating', 'rating_count',
        'shipping_settings', 'bio', 'return_policy', 'vacation_mode',
        'vacation_message', 'logo_path', 'handling_fee', 'free_shipping_threshold',
        'ad_credit', 'banner_path', 'announcement', 'announcement_until', 'featured_product_ids',
        'referral_bonus_pct', 'referral_bonus_until', 'theme', 'loyalty', 'offsite_ads_opt_out',
        'ship_from', 'return_window_days', 'return_shipping', 'vacation_until', 'vacation_pause_sales',
    ];

    protected $casts = [
        'ein_encrypted' => 'encrypted',
        'shipping_settings' => 'array',
        'stripe_onboarding_complete' => 'boolean',
        'vacation_mode' => 'boolean',
        'commission_rate_override' => 'decimal:2',
        'rating' => 'decimal:2',
        'handling_fee' => 'decimal:2',
        'free_shipping_threshold' => 'decimal:2',
        'tier_evaluated_at' => 'datetime',
        'ad_credit' => 'decimal:2',
        'announcement_until' => 'datetime',
        'announcement_notified_at' => 'datetime',
        'featured_product_ids' => 'array',
        'referral_bonus_pct' => 'decimal:2',
        'referral_bonus_until' => 'datetime',
        'theme' => 'array',
        'loyalty' => 'array',
        'offsite_ads_opt_out' => 'boolean',
        'ship_from' => 'array',
        'return_window_days' => 'integer',
        'vacation_until' => 'datetime',
        'vacation_pause_sales' => 'boolean',
    ];

    /** Store-wide handling time in business days (upper end of the chosen range). */
    public function handlingDays(): int
    {
        return match ($this->shipping_settings['processing_days'] ?? '1-2') {
            '3-5' => 5,
            '5-7' => 7,
            default => 2,
        };
    }

    /** A complete ship-from address — needed before buying carrier labels. */
    public function hasShipFrom(): bool
    {
        $a = $this->ship_from ?? [];

        return filled($a['line1'] ?? null) && filled($a['city'] ?? null) && filled($a['state'] ?? null) && filled($a['postal_code'] ?? null);
    }

    /**
     * "Usually replies within 2 hours" for buyers, from the seller's real 30-day first-response
     * average (the same number they see in their inbox). Cached — it's on every product page.
     */
    public function responseLabel(): ?string
    {
        $hours = cache()->remember("seller.{$this->user_id}.response_hours", 3600,
            fn () => app(\App\Services\MessagingService::class)->responseStats($this->user_id)['avg_first_response_hours'] ?? -1);

        return match (true) {
            $hours === null || $hours < 0 => null,
            $hours < 1 => 'Usually replies within an hour',
            $hours <= 24 => 'Usually replies within '.(int) ceil($hours).' '.\Illuminate\Support\Str::plural('hour', (int) ceil($hours)),
            default => 'Usually replies within '.(int) ceil($hours / 24).' days',
        };
    }

    /** Away and not taking new orders. */
    public function salesPaused(): bool
    {
        return $this->isOnVacation() && $this->vacation_pause_sales;
    }

    protected static function booted(): void
    {
        static::saving(function (SellerProfile $profile) {
            if (blank($profile->slug) && filled($profile->business_name)) {
                $profile->slug = static::uniqueSlug($profile->business_name, $profile->id);
            }
        });
    }

    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'store';
        $slug = $base;
        $i = 2;

        while (static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function commissionRate(): float
    {
        if ($this->commission_rate_override !== null) {
            return (float) $this->commission_rate_override;
        }

        $tierRate = config("marketplace.seller_tiers.{$this->tier}.commission_rate");
        $rate = $tierRate !== null
            ? (float) $tierRate
            : (float) config('marketplace.default_commission_rate');

        // Active seller-referral bonus stacks on top as a discount.
        if ($this->referral_bonus_pct !== null && $this->referral_bonus_until?->isFuture()) {
            $rate = max(0, $rate - (float) $this->referral_bonus_pct);
        }

        return round($rate, 2);
    }

    public function hasReferralBonus(): bool
    {
        return $this->referral_bonus_pct !== null && $this->referral_bonus_until?->isFuture();
    }

    public function tierConfig(): array
    {
        return config("marketplace.seller_tiers.{$this->tier}")
            ?? config('marketplace.seller_tiers.standard');
    }

    public function tierLabel(): string
    {
        return $this->tierConfig()['label'] ?? 'Standard';
    }

    public function hasBadge(): bool
    {
        return in_array($this->tier, ['rising', 'top_rated', 'powerseller'], true);
    }

    /** Vacation on, and (if an end date was set) not past it yet. */
    public function isOnVacation(): bool
    {
        return (bool) $this->vacation_mode && (! $this->vacation_until || $this->vacation_until->isFuture());
    }

    public function activeAnnouncement(): ?string
    {
        if (blank($this->announcement)) {
            return null;
        }

        return (! $this->announcement_until || $this->announcement_until->isFuture())
            ? $this->announcement
            : null;
    }

    public function followerCount(): int
    {
        return $this->user->storeFollowers()->count();
    }

    public function themeAccent(): string
    {
        return $this->theme['accent'] ?? '#DD1E25';
    }

    public function loyaltyEnabled(): bool
    {
        return (bool) ($this->loyalty['enabled'] ?? false);
    }

    public function pointsPerDollar(): int
    {
        return (int) ($this->loyalty['points_per_dollar'] ?? 5);
    }
}
