<?php

namespace App\Http\Controllers\Storefront;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use Illuminate\Http\Request;

class ReturnController extends Controller
{
    public function request(Request $request, OrderItem $orderItem)
    {
        abort_unless($orderItem->order->buyer_id === auth()->id(), 403);

        $request->validate(['reason' => 'required|string|max:1000']);

        $orderItem->update(['status' => OrderStatus::ReturnRequested]);
        $orderItem->statusHistory()->create([
            'status' => OrderStatus::ReturnRequested->value,
            'note' => $request->reason,
            'changed_by' => auth()->id(),
        ]);

        return back()->with('success', 'Return requested — the seller/admin will review it.');
    }
}
