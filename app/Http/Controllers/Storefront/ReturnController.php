<?php

namespace App\Http\Controllers\Storefront;

use App\Services\Buyer\OrderActionRefused;
use App\Services\Buyer\OrderActions;
use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\ReturnRequest;
use Illuminate\Http\Request;

class ReturnController extends Controller
{
    public function escalate(Request $request, ReturnRequest $returnRequest, OrderActions $actions)
    {
        abort_unless($returnRequest->user_id === auth()->id(), 403);
        $data = $request->validate(['note' => 'nullable|string|max:1000']);

        try {
            $actions->escalateReturn($returnRequest, auth()->user(), $data['note'] ?? null);
        } catch (OrderActionRefused $e) {
            abort($e->status, $e->getMessage());
        }

        return back()->with('success', 'Escalated — Panmotive support will review within 1 business day.');
    }

    public function request(Request $request, OrderItem $orderItem, OrderActions $actions)
    {
        abort_unless($orderItem->order->buyer_id === auth()->id(), 403);

        $data = $request->validate([
            'reason' => 'required|string|max:120',
            'detail' => 'nullable|string|max:2000',
        ]);

        try {
            $rma = $actions->requestReturn($orderItem, auth()->user(), $data['reason'], $data['detail'] ?? null);
        } catch (OrderActionRefused $e) {
            abort($e->status, $e->getMessage());
        }

        return back()->with('success', "Return started — your RMA number is {$rma->rma_number}.");
    }
}
