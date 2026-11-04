<?php

namespace App\Http\Controllers\Storefront;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\BuyerClaim;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ClaimController extends Controller
{
    private const ELIGIBLE = [
        OrderStatus::Shipped->value, OrderStatus::OutForDelivery->value,
        OrderStatus::Delivered->value, OrderStatus::PayoutReleased->value,
        OrderStatus::ReturnRequested->value, OrderStatus::ReturnApproved->value,
    ];

    public function store(Request $request, OrderItem $orderItem)
    {
        abort_unless($orderItem->order->buyer_id === auth()->id(), 403);

        $data = $request->validate([
            'reason' => 'required|in:'.implode(',', array_keys(BuyerClaim::REASONS)),
            'detail' => 'required|string|max:2000',
        ]);

        if (! in_array($orderItem->status->value, self::ELIGIBLE, true)) {
            throw ValidationException::withMessages(['reason' => 'This item is not eligible for a claim right now.']);
        }

        if (BuyerClaim::where('order_item_id', $orderItem->id)->whereIn('status', ['open', 'seller_responded', 'escalated'])->exists()) {
            throw ValidationException::withMessages(['reason' => 'You already have an open claim for this item.']);
        }

        $claim = BuyerClaim::create([
            'order_item_id' => $orderItem->id,
            'buyer_id' => auth()->id(),
            'seller_id' => $orderItem->seller_id,
            'reason' => $data['reason'],
            'detail' => $data['detail'],
            'amount' => $orderItem->line_total,
            'status' => 'open',
        ]);

        $orderItem->seller?->notify(new \App\Notifications\BuyerClaimFiled($claim));

        return back()->with('success', "Claim {$claim->claim_number} filed. The seller has 72 hours to respond before Panmotive steps in.");
    }
}
