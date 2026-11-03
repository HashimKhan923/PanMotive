<?php

namespace App\Services\Marketing;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class AudienceResolver
{
    public const TYPES = [
        'all_customers' => 'Everyone who has bought from me',
        'repeat_customers' => 'Repeat customers (2+ orders)',
        'followers' => 'Store followers',
        'wishlisted' => 'Buyers who wishlisted my products',
        'abandoned_cart' => 'Left my product in their cart (14 days)',
        'lapsed' => 'Past customers, no order in 90 days',
    ];

    /** @return \Illuminate\Database\Eloquent\Builder<User> */
    public function query(int $sellerId, string $type)
    {
        $myOrderUserIds = fn () => DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.seller_id', $sellerId)
            ->whereNotNull('orders.buyer_id')
            ->distinct()->pluck('orders.buyer_id');

        return match ($type) {
            'repeat_customers' => User::whereIn('id', DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('order_items.seller_id', $sellerId)
                ->whereNotNull('orders.buyer_id')
                ->groupBy('orders.buyer_id')
                ->havingRaw('COUNT(DISTINCT orders.id) >= 2')
                ->pluck('orders.buyer_id')),

            'followers' => User::whereIn('id', DB::table('seller_followers')->where('seller_id', $sellerId)->pluck('user_id')),

            'wishlisted' => User::whereIn('id', DB::table('wishlist_items')
                ->join('wishlists', 'wishlists.id', '=', 'wishlist_items.wishlist_id')
                ->join('products', 'products.id', '=', 'wishlist_items.product_id')
                ->where('products.seller_id', $sellerId)
                ->whereNotNull('wishlists.user_id')
                ->distinct()->pluck('wishlists.user_id')),

            'abandoned_cart' => User::whereIn('id', DB::table('cart_items')
                ->join('carts', 'carts.id', '=', 'cart_items.cart_id')
                ->join('products', 'products.id', '=', 'cart_items.product_id')
                ->where('products.seller_id', $sellerId)
                ->whereNotNull('carts.user_id')
                ->where('carts.updated_at', '>=', now()->subDays(14))
                ->distinct()->pluck('carts.user_id')),

            'lapsed' => User::whereIn('id', $myOrderUserIds())
                ->whereDoesntHave('orders', fn ($q) => $q->where('created_at', '>=', now()->subDays(90))),

            default => User::whereIn('id', $myOrderUserIds()),
        };
    }

    public function count(int $sellerId, string $type): int
    {
        return $this->query($sellerId, $type)->whereNotNull('email')->count();
    }
}
