<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Support\Compare;
use Illuminate\Http\Request;

class CompareController extends Controller
{
    public function toggle(Request $request, Compare $compare)
    {
        $data = $request->validate(['product_id' => 'required|integer|exists:products,id']);

        $id = (int) $data['product_id'];
        $wasIn = $compare->has($id);
        $compare->toggle($id);
        $inList = $compare->has($id);

        return response()->json([
            'in_list' => $inList,
            'count' => $compare->count(),
            'full' => ! $inList && $compare->count() >= Compare::MAX,
            'rejected' => ! $wasIn && ! $inList, // toggle refused: list was full
            'max' => Compare::MAX,
        ]);
    }
}
