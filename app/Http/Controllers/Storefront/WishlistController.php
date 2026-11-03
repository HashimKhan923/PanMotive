<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Cart\WishlistService;
use Illuminate\Http\Request;

class WishlistController extends Controller
{
    public function toggle(Request $request, WishlistService $wishlist)
    {
        $data = $request->validate(['product_id' => 'required|integer|exists:products,id']);

        $inList = $wishlist->toggle((int) $data['product_id']);

        return response()->json([
            'in_list' => $inList,
            'count' => $wishlist->count(),
        ]);
    }

    public function ids(WishlistService $wishlist)
    {
        return response()->json([
            'ids' => $wishlist->ids(),
            'count' => $wishlist->count(),
        ]);
    }
}
