<?php

namespace App\Http\Controllers\Storefront;

use App\Services\Buyer\OrderActionRefused;
use App\Services\Buyer\OrderActions;
use App\Http\Controllers\Controller;
use App\Models\BuyerClaim;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ClaimController extends Controller
{
    public function store(Request $request, OrderItem $orderItem, OrderActions $actions)
    {
        abort_unless($orderItem->order->buyer_id === auth()->id(), 403);

        $data = $request->validate([
            'reason' => 'required|in:'.implode(',', array_keys(BuyerClaim::REASONS)),
            'detail' => 'required|string|max:2000',
        ]);

        try {
            $claim = $actions->fileClaim($orderItem, auth()->user(), $data['reason'], $data['detail']);
        } catch (OrderActionRefused $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        return back()->with('success', "Claim {$claim->claim_number} filed. The seller has 72 hours to respond before Panmotive steps in.");
    }
}
