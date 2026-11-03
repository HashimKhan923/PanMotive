<?php

namespace App\Services\Buyer;

use App\Models\Product;
use App\Models\User;
use App\Notifications\PriceDropAlert;
use Illuminate\Support\Facades\DB;

class WishlistAlertService
{
    /** Minimum drop from the saved price before we bother the buyer. */
    public const MIN_DROP_PCT = 5.0;

    /** Notify wishlist owners whose saved items have dropped in price. Returns notifications sent. */
    public function sweep(): int
    {
        $sent = 0;

        DB::table('wishlist_items')
            ->join('wishlists', 'wishlists.id', '=', 'wishlist_items.wishlist_id')
            ->join('products', 'products.id', '=', 'wishlist_items.product_id')
            ->whereNotNull('wishlists.user_id')
            ->whereNotNull('wishlist_items.price_at_add')
            ->where('products.status', 'active')
            ->where(function ($q) {
                $q->whereNull('wishlist_items.price_drop_notified_at')
                    ->orWhere('wishlist_items.price_drop_notified_at', '<', now()->subDays(14));
            })
            ->select(
                'wishlist_items.id',
                'wishlist_items.price_at_add',
                'wishlists.user_id',
                'wishlist_items.product_id',
            )
            ->orderBy('wishlist_items.id')
            ->chunk(200, function ($rows) use (&$sent) {
                foreach ($rows as $row) {
                    $product = Product::find($row->product_id);
                    if (! $product) {
                        continue;
                    }

                    $now = $product->effectivePrice();
                    $was = (float) $row->price_at_add;
                    if ($was <= 0 || $now >= $was) {
                        continue;
                    }
                    if (($was - $now) / $was * 100 < self::MIN_DROP_PCT) {
                        continue;
                    }

                    $user = User::find($row->user_id);
                    if (! $user) {
                        continue;
                    }

                    $user->notify(new PriceDropAlert($product, $was, $now));
                    DB::table('wishlist_items')->where('id', $row->id)
                        ->update(['price_drop_notified_at' => now()]);
                    $sent++;
                }
            });

        return $sent;
    }
}
