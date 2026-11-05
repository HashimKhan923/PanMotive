<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Commission Rate
    |--------------------------------------------------------------------------
    |
    | Platform commission as a percentage of each order line total. Used when a
    | seller has no commission_rate_override. Snapshotted onto order_items at
    | sale time so later changes never alter historical payouts.
    |
    */

    'default_commission_rate' => env('DEFAULT_COMMISSION_RATE', 10.00), // percent

    /*
    |--------------------------------------------------------------------------
    | Auto-Confirm Delivery Window
    |--------------------------------------------------------------------------
    |
    | Days after delivery before a line item auto-confirms and its payout is
    | released, if the buyer takes no action.
    |
    */

    'auto_confirm_delivery_days' => env('AUTO_CONFIRM_DELIVERY_DAYS', 7),

    /*
    | Returns: buyers can open a return this many days after delivery.
    | Unpaid orders (abandoned at the payment step, or a Subscribe & Save order the
    | buyer hasn't paid) are cancelled after `unpaid_order_hours` and their stock freed.
    */
    'returns' => [
        'window_days' => env('RETURN_WINDOW_DAYS', 30),
    ],
    'unpaid_order_hours' => env('UNPAID_ORDER_HOURS', 48),

    /*
    |--------------------------------------------------------------------------
    | Loyalty & Retention
    |--------------------------------------------------------------------------
    |
    | Tiers are evaluated in order — the highest whose min_spend the buyer's
    | lifetime_spend meets wins. cashback_rate is a percentage of each line
    | total, credited when delivery confirms (same trigger as seller payout).
    |
    */

    'loyalty_tiers' => [
        'bronze' => ['min_spend' => 0, 'cashback_rate' => 1.0, 'free_shipping_threshold' => 75],
        'silver' => ['min_spend' => 500, 'cashback_rate' => 2.0, 'free_shipping_threshold' => 50],
        'gold' => ['min_spend' => 2000, 'cashback_rate' => 3.5, 'free_shipping_threshold' => 0],
    ],

    'referral_reward_amount' => 10.00,

    /*
    |--------------------------------------------------------------------------
    | Instant Payout on Ship
    |--------------------------------------------------------------------------
    |
    | Sellers can pull their payout forward to the moment they ship, instead of
    | waiting for delivery confirmation. The platform fronts the cash and recoups
    | it when the normal escrow release fires. Fee is a % of the payout; waived
    | for the listed tiers.
    |
    */

    'instant_payout_fee_pct' => env('INSTANT_PAYOUT_FEE_PCT', 1.5),
    'instant_payout_free_tiers' => ['top_rated', 'powerseller'],

    /*
    |--------------------------------------------------------------------------
    | Form 1099-K reporting threshold
    |--------------------------------------------------------------------------
    |
    | Federal rule (restored by the One Big Beautiful Bill Act, 2025): a 1099-K
    | is required when a seller's gross payments exceed $20,000 AND they have
    | more than 200 transactions in the calendar year. Some states set lower
    | thresholds — check with a tax professional before launch.
    |
    */

    'tax_1099k_threshold' => env('TAX_1099K_THRESHOLD', 20000),
    'tax_1099k_min_transactions' => env('TAX_1099K_MIN_TRANSACTIONS', 200),

    // Marketplace-facilitator sales tax through Stripe Tax (see SalesTaxService). Only turn this on
    // once Stripe Tax is active with your state registrations: it also tells sellers tax is handled.
    'sales_tax' => [
        'collecting' => (bool) env('SALES_TAX_COLLECTING', false),
        'product_tax_code' => env('SALES_TAX_PRODUCT_CODE', 'txcd_99999999'), // General - Tangible Goods
        'shipping_tax_code' => env('SALES_TAX_SHIPPING_CODE', 'txcd_92010001'), // Shipping
    ],


    'abandoned_cart_hours' => env('ABANDONED_CART_HOURS', 4),

    /*
    |--------------------------------------------------------------------------
    | Seller Performance Tiers
    |--------------------------------------------------------------------------
    |
    | Evaluated worst→best; the highest tier whose every threshold is met wins.
    | `commission_rate` (when set) overrides the platform default for that
    | seller — a direct, earned discount. `min_orders` gates promotion so a
    | seller with one perfect order isn't instantly "Powerseller".
    |
    */

    'seller_tiers' => [
        'standard' => [
            'label' => 'Standard', 'min_orders' => 0,
            'on_time_ship' => 0, 'max_defect_rate' => 100, 'response_rate' => 0,
            'commission_rate' => null,
            'perks' => ['Full access to the seller portal', 'Escrow-protected payouts'],
        ],
        'rising' => [
            'label' => 'Rising Seller', 'min_orders' => 10,
            'on_time_ship' => 90, 'max_defect_rate' => 6, 'response_rate' => 70,
            'commission_rate' => 9.0,
            'perks' => ['1% lower commission', '"Rising Seller" badge on your store', 'Priority email support'],
        ],
        'top_rated' => [
            'label' => 'Top Rated', 'min_orders' => 40,
            'on_time_ship' => 95, 'max_defect_rate' => 3, 'response_rate' => 85,
            'commission_rate' => 8.0,
            'perks' => ['2% lower commission', '"Top Rated" badge everywhere your parts appear', 'Search ranking boost', 'Instant payout on ship (fee-free)'],
        ],
        'powerseller' => [
            'label' => 'Powerseller', 'min_orders' => 120,
            'on_time_ship' => 98, 'max_defect_rate' => 1.5, 'response_rate' => 90,
            'commission_rate' => 6.5,
            'perks' => ['3.5% lower commission', '"Powerseller" badge + homepage feature slots', 'Top search placement', 'Dedicated account manager', 'Early access to new tools'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Panmotive Plus — buyer membership
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Listings
    |--------------------------------------------------------------------------
    | review_required = true holds every new/edited listing in "pending_review"
    | until an admin approves it. Default false: an approved seller's listings
    | publish immediately (they can still be taken down reactively).
    */

    'listings' => [
        'review_required' => env('LISTING_REVIEW_REQUIRED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Seller identity verification (KYC)
    |--------------------------------------------------------------------------
    | The submission + admin-review flow always runs. `enforce_payouts` makes an
    | approved verification a hard requirement before escrow releases.
    */

    'kyc' => [
        'required' => true,
        'enforce_payouts' => env('KYC_ENFORCE_PAYOUTS', false),
    ],

    'plus' => [
        'price' => 59.00,          // annual
        'term_months' => 12,
        'cashback_bonus_rate' => 2.0,   // extra wallet cashback % on top of loyalty tier
        'perks' => [
            'Free shipping on every order, no minimum',
            '2% extra cashback on all purchases',
            'Members-only pricing and early deal access',
            'Priority support',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Offsite Ads (Etsy model)
    |--------------------------------------------------------------------------
    |
    | Panmotive advertises sellers' listings on Facebook/Instagram, Google etc.
    | from its own ad accounts. Sellers pay nothing up front — only a fee on an
    | order placed within `attribution_days` of a buyer clicking one of their
    | ads. Sellers above `large_seller_threshold` (sales, trailing 12 months)
    | pay the lower rate and can't opt out.
    |
    */

    // Seller-to-buyer marketing guardrails. CAN-SPAM requires a working unsubscribe and a
    // physical postal address in every marketing email.
    'marketing' => [
        'frequency_cap_hours' => (int) env('MARKETING_FREQUENCY_CAP_HOURS', 24), // per buyer, per store
        'campaigns_per_week' => (int) env('MARKETING_CAMPAIGNS_PER_WEEK', 3),    // per store
        // Placeholder until the real registered address is set in .env.
        'postal_address' => env('MARKETING_POSTAL_ADDRESS') ?: 'Panmotive, Inc. · 2100 Commerce St · Dallas, TX 75201 · USA',
        'announcement_cooldown_hours' => 24,
        'affiliate_hold_days' => (int) env('AFFILIATE_HOLD_DAYS', 30), // commission payable once the return window has passed
    ],

    // Buyer messages: sellers are expected to answer within this many hours (Amazon/eBay use 24).
    // Q&A questions get a looser target since they're public and less urgent.
    'messages' => [
        'response_target_hours' => (int) env('MESSAGE_RESPONSE_TARGET_HOURS', 24),
        'question_target_hours' => (int) env('QUESTION_RESPONSE_TARGET_HOURS', 48),
        'max_attachments' => 4,
    ],

    /*
    | Site switches. Defaults here; the admin changes them in Platform → Settings
    | (saved in platform_settings, applied by App\\Support\\PlatformSettings).
    */
    'platform' => [
        'maintenance' => false,
        'maintenance_message' => '',
        'checkout_enabled' => true,
        'seller_signups' => true,
        'announcement' => '',
        'announcement_link' => '',
        'announcement_tone' => 'accent',
        'announcement_until' => '',
        'support_email' => '',
        'hero_badge' => 'Automotive marketplace',
        'hero_line_1' => 'Upgrade',
        'hero_line_2' => '',
        'hero_accent' => 'your drive.',
        'hero_text' => 'Find parts and accessories for your vehicle. Check the listed vehicle compatibility before ordering.',
        'hero_point_1' => 'Search by part number',
        'hero_point_2' => 'Payment held until delivery',
        'hero_point_3' => 'Cashback on delivered orders',
    ],

    'offsite_ads' => [
        'enabled' => env('OFFSITE_ADS_ENABLED', true),
        'fee_pct' => 15.0,
        'large_seller_fee_pct' => 12.0,
        'large_seller_threshold' => 10000,
        'fee_cap' => 100.0,
        'attribution_days' => 30,
        // Optional shared secret: if set, feeds require ?key=... (keeps scrapers off).
        'feed_key' => env('OFFSITE_ADS_FEED_KEY'),
        // Link code => channel. Feed links carry ?oa=<code>.
        'channels' => [
            'fb' => 'Facebook',
            'ig' => 'Instagram',
            'google' => 'Google',
            'pin' => 'Pinterest',
            'bing' => 'Bing',
        ],
    ],

];
