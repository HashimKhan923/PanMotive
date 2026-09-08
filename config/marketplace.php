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

    'abandoned_cart_hours' => env('ABANDONED_CART_HOURS', 4),

];
