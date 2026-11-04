<?php

namespace App\Support;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Settings the admin can change without a deploy. Each one overrides a config value, so the code
 * that reads config('marketplace.…') picks up the admin's choice with no other changes. Secrets
 * (API keys) are deliberately not here: they stay in the server's .env.
 */
class PlatformSettings
{
    private const CACHE_KEY = 'platform_settings.v1';

    /** @return array<string, array{label: string, hint: string, icon: string, fields: array<string, array<string, mixed>>}> */
    public static function registry(): array
    {
        return [
            'switches' => ['label' => 'Site switches', 'hint' => 'Open and close parts of the marketplace instantly.', 'icon' => 'shield', 'fields' => [
                'marketplace.platform.maintenance' => ['label' => 'Maintenance mode', 'type' => 'bool', 'hint' => 'Shoppers and sellers see a "back soon" page. Staff can still use the whole site.', 'danger' => true],
                'marketplace.platform.maintenance_message' => ['label' => 'Maintenance message', 'type' => 'text', 'hint' => 'Shown on the "back soon" page.'],
                'marketplace.platform.checkout_enabled' => ['label' => 'Checkout open', 'type' => 'bool', 'hint' => 'Turn off to stop new orders. Shoppers can still browse.', 'danger' => true],
                'marketplace.platform.seller_signups' => ['label' => 'New seller sign-ups open', 'type' => 'bool', 'hint' => 'Turn off to stop new seller applications.'],
                'marketplace.listings.review_required' => ['label' => 'Review listings before they go live', 'type' => 'bool', 'hint' => 'New listings wait in Products → Pending review.'],
                'marketplace.kyc.enforce_payouts' => ['label' => 'Require verified identity for payouts', 'type' => 'bool', 'hint' => 'Sellers without approved documents are not paid.'],
                'marketplace.offsite_ads.enabled' => ['label' => 'Offsite Ads program', 'type' => 'bool', 'hint' => 'Advertise listings off-site and charge the attribution fee.'],
            ]],
            'commerce' => ['label' => 'Fees & orders', 'hint' => 'What sellers pay and how long order steps last.', 'icon' => 'dollar', 'fields' => [
                'marketplace.default_commission_rate' => ['label' => 'Default commission', 'type' => 'number', 'suffix' => '%', 'min' => 0, 'max' => 50, 'hint' => 'Used unless a tier or a seller override says otherwise.'],
                'marketplace.instant_payout_fee_pct' => ['label' => 'Instant payout fee', 'type' => 'number', 'suffix' => '%', 'min' => 0, 'max' => 20],
                'marketplace.auto_confirm_delivery_days' => ['label' => 'Auto-confirm delivery after', 'type' => 'int', 'suffix' => 'days', 'min' => 1, 'max' => 60],
                'marketplace.returns.window_days' => ['label' => 'Default return window', 'type' => 'int', 'suffix' => 'days', 'min' => 0, 'max' => 365],
                'marketplace.unpaid_order_hours' => ['label' => 'Cancel unpaid orders after', 'type' => 'int', 'suffix' => 'hours', 'min' => 1, 'max' => 720],
                'marketplace.abandoned_cart_hours' => ['label' => 'Abandoned cart reminder after', 'type' => 'int', 'suffix' => 'hours', 'min' => 1, 'max' => 720],
            ]],
            'tiers' => ['label' => 'Seller tiers', 'hint' => 'Commission each performance tier pays.', 'icon' => 'chart', 'fields' => [
                'marketplace.seller_tiers.rising.commission_rate' => ['label' => 'Rising Seller commission', 'type' => 'number', 'suffix' => '%', 'min' => 0, 'max' => 50],
                'marketplace.seller_tiers.top_rated.commission_rate' => ['label' => 'Top Rated commission', 'type' => 'number', 'suffix' => '%', 'min' => 0, 'max' => 50],
                'marketplace.seller_tiers.powerseller.commission_rate' => ['label' => 'Powerseller commission', 'type' => 'number', 'suffix' => '%', 'min' => 0, 'max' => 50],
            ]],
            'rewards' => ['label' => 'Buyer rewards', 'hint' => 'Cashback, referrals and Panmotive Plus.', 'icon' => 'sparkles', 'fields' => [
                'marketplace.loyalty_tiers.bronze.cashback_rate' => ['label' => 'Bronze cashback', 'type' => 'number', 'suffix' => '%', 'min' => 0, 'max' => 25],
                'marketplace.loyalty_tiers.silver.cashback_rate' => ['label' => 'Silver cashback', 'type' => 'number', 'suffix' => '%', 'min' => 0, 'max' => 25],
                'marketplace.loyalty_tiers.gold.cashback_rate' => ['label' => 'Gold cashback', 'type' => 'number', 'suffix' => '%', 'min' => 0, 'max' => 25],
                'marketplace.referral_reward_amount' => ['label' => 'Referral reward', 'type' => 'number', 'prefix' => '$', 'min' => 0, 'max' => 500],
                'marketplace.plus.price' => ['label' => 'Plus membership price (yearly)', 'type' => 'number', 'prefix' => '$', 'min' => 0, 'max' => 1000],
                'marketplace.plus.cashback_bonus_rate' => ['label' => 'Plus extra cashback', 'type' => 'number', 'suffix' => '%', 'min' => 0, 'max' => 25],
            ]],
            'marketing' => ['label' => 'Marketing rules', 'hint' => 'Limits that protect buyers from too many messages.', 'icon' => 'mail', 'fields' => [
                'marketplace.marketing.frequency_cap_hours' => ['label' => 'Minimum gap between store emails', 'type' => 'int', 'suffix' => 'hours', 'min' => 1, 'max' => 720],
                'marketplace.marketing.campaigns_per_week' => ['label' => 'Campaigns per store, per week', 'type' => 'int', 'min' => 0, 'max' => 50],
                'marketplace.marketing.postal_address' => ['label' => 'Postal address in marketing emails', 'type' => 'text', 'hint' => 'US CAN-SPAM law requires a real postal address.'],
                'marketplace.marketing.affiliate_hold_days' => ['label' => 'Affiliate commission hold', 'type' => 'int', 'suffix' => 'days', 'min' => 0, 'max' => 120],
            ]],
            'offsite' => ['label' => 'Offsite Ads fees', 'hint' => 'What sellers pay when one of our ads brings a sale.', 'icon' => 'external', 'fields' => [
                'marketplace.offsite_ads.fee_pct' => ['label' => 'Fee', 'type' => 'number', 'suffix' => '%', 'min' => 0, 'max' => 50],
                'marketplace.offsite_ads.large_seller_fee_pct' => ['label' => 'Fee for large sellers', 'type' => 'number', 'suffix' => '%', 'min' => 0, 'max' => 50],
                'marketplace.offsite_ads.large_seller_threshold' => ['label' => 'Large seller yearly sales', 'type' => 'number', 'prefix' => '$', 'min' => 0, 'max' => 10000000],
                'marketplace.offsite_ads.fee_cap' => ['label' => 'Fee cap per order', 'type' => 'number', 'prefix' => '$', 'min' => 0, 'max' => 10000],
                'marketplace.offsite_ads.attribution_days' => ['label' => 'Attribution window', 'type' => 'int', 'suffix' => 'days', 'min' => 1, 'max' => 90],
            ]],
            'service' => ['label' => 'Service targets', 'hint' => 'Response times sellers are measured against.', 'icon' => 'clock', 'fields' => [
                'marketplace.messages.response_target_hours' => ['label' => 'Message reply target', 'type' => 'int', 'suffix' => 'hours', 'min' => 1, 'max' => 168],
                'marketplace.messages.question_target_hours' => ['label' => 'Question answer target', 'type' => 'int', 'suffix' => 'hours', 'min' => 1, 'max' => 336],
            ]],
        ];
    }

    /** Announcement bar and site content are edited on the Content screen, but stored the same way. */
    public static function contentKeys(): array
    {
        return [
            'marketplace.platform.announcement', 'marketplace.platform.announcement_link',
            'marketplace.platform.announcement_tone', 'marketplace.platform.announcement_until',
            'marketplace.platform.support_email',
        ];
    }

    public static function fields(): array
    {
        return collect(self::registry())->flatMap(fn ($group) => $group['fields'])->all();
    }

    public static function apply(): void
    {
        try {
            $saved = Cache::rememberForever(self::CACHE_KEY, fn () => PlatformSetting::pluck('value', 'key')->all());
        } catch (\Throwable) {
            return; // before the first migrate
        }

        $fields = self::fields();
        foreach ($saved as $key => $value) {
            config([$key => self::cast($value, $fields[$key]['type'] ?? 'text')]);
        }
    }

    /**
     * Save only what changed and report it, so the audit log says exactly who changed what.
     *
     * @param  array<string, mixed>  $values  config key => new value
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public static function save(array $values, ?int $userId): array
    {
        $fields = self::fields();
        $changes = [];

        foreach ($values as $key => $value) {
            $type = $fields[$key]['type'] ?? 'text';
            $new = self::cast($value, $type);
            $old = config($key);

            if ((string) json_encode($old) === (string) json_encode($new)
                || ($type !== 'bool' && $type !== 'text' && (float) $old === (float) $new)) {
                continue;
            }

            PlatformSetting::updateOrCreate(['key' => $key], ['value' => is_bool($new) ? ($new ? '1' : '0') : $new, 'updated_by' => $userId]);
            config([$key => $new]);
            $changes[$key] = ['from' => $old, 'to' => $new];
        }

        if ($changes) {
            Cache::forget(self::CACHE_KEY);
        }

        return $changes;
    }

    private static function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $value,
            'number' => (float) $value,
            default => $value === null ? '' : (string) $value,
        };
    }
}
