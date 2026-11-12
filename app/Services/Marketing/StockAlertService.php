<?php

namespace App\Services\Marketing;

use App\Models\Product;
use App\Models\StockAlert;
use App\Notifications\BackInStock;

/** Tells everyone waiting on a listing that it's back, once. */
class StockAlertService
{
    public function restocked(Product $product): int
    {
        if ($product->status !== 'active') {
            return 0;
        }

        $sent = 0;
        StockAlert::where('product_id', $product->id)->whereNull('notified_at')->with('user')
            ->chunkById(200, function ($alerts) use ($product, &$sent) {
                foreach ($alerts as $alert) {
                    // Claim it first, so two saves in a row can't email twice.
                    if (! StockAlert::whereKey($alert->id)->whereNull('notified_at')->update(['notified_at' => now()])) {
                        continue;
                    }
                    try {
                        $alert->user?->notify(new BackInStock($product));
                        $sent++;
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });

        return $sent;
    }

    /** True when a save took stock from nothing to something. */
    public static function cameBack(mixed $before, mixed $after): bool
    {
        return (int) $before <= 0 && (int) $after > 0;
    }
}
