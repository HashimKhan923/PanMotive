<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Services\Payments\PayoutReversalService;
use App\Services\Payments\RefundService;
use Illuminate\Http\Request;
use RuntimeException;

class DisputeController extends Controller
{
    public function index()
    {
        $items = OrderItem::whereIn('status', [OrderStatus::ReturnRequested, OrderStatus::Disputed])
            ->with('order.buyer', 'seller.sellerProfile', 'product', 'statusHistory')
            ->latest()->paginate(20);

        return view('admin.disputes.index', compact('items'));
    }

    public function approveReturn(OrderItem $orderItem, RefundService $refundService)
    {
        abort_unless(auth()->user()->can('refunds.issue'), 403);

        try {
            $refundService->refund($orderItem);
        } catch (RuntimeException $e) {
            return back()->withErrors(['refund' => $e->getMessage()]);
        }

        $orderItem->statusHistory()->create([
            'status' => OrderStatus::Refunded->value,
            'note' => 'Return approved and refunded by admin',
            'changed_by' => auth()->id(),
        ]);

        return back()->with('success', 'Return approved — buyer refunded.');
    }

    public function denyReturn(Request $request, OrderItem $orderItem)
    {
        abort_unless(auth()->user()->can('disputes.resolve'), 403);

        $request->validate(['reason' => 'required|string|max:1000']);

        $orderItem->update(['status' => OrderStatus::Delivered]); // revert to prior resolved state
        $orderItem->statusHistory()->create([
            'status' => 'return_denied',
            'note' => $request->reason,
            'changed_by' => auth()->id(),
        ]);

        return back()->with('success', 'Return denied.');
    }

    public function reverseSellerPayout(Request $request, OrderItem $orderItem)
    {
        // For the case RefundService flagged: payout already released, needs manual seller-side reversal.
        abort_unless(auth()->user()->can('refunds.issue'), 403);
        abort_unless($orderItem->payout_released_at !== null, 400);

        $request->validate(['reason' => 'required|string|max:1000']);

        // Creates a Stripe Transfer Reversal — pulls the funds back from the
        // seller's connected account balance, then issues the buyer refund.
        app(PayoutReversalService::class)->reverse($orderItem);

        $orderItem->update(['status' => OrderStatus::Refunded]);
        $orderItem->statusHistory()->create([
            'status' => 'payout_reversed_and_refunded',
            'note' => $request->reason,
            'changed_by' => auth()->id(),
        ]);

        return back()->with('success', 'Seller payout reversed and buyer refunded.');
    }
}
