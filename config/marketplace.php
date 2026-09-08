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

];
