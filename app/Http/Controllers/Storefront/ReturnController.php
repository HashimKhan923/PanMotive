<?php

namespace App\Http\Controllers\Storefront;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\AdminAlert;
use App\Models\OrderItem;
use App\Models\ReturnRequest;
use Illuminate\Http\Request;

class ReturnController extends Controller
{
    public function escalate(Request $request, ReturnRequest $returnRequest)
    {
        abort_unless($returnRequest->user_id === auth()->id(), 403);
        abort_unless(in_array($returnRequest->status, ['requested', 'approved'], true), 422, 'This return can no longer be escalated.');

        $data = $request->validate(['note' => 'nullable|string|max:1000']);

        $returnRequest->update(['escalated_at' => now(), 'escalated_by' => 'buyer']);
        $returnRequest->log('escalated', 'buyer', auth()->id(), $data['note'] ?? 'Buyer escalated to PanMotive support');

        AdminAlert::create([
            'type' => 'return_escalated',
            'message' => "Return {$returnRequest->rma_number} escalated by the buyer.",
            'related_order_item_id' => $returnRequest->order_item_id,
        ]);

        return back()->with('success', 'Escalated — PanMotive support will review within 1 business day.');
    }

    public function request(Request $request, OrderItem $orderItem)
    {
        abort_unless($orderItem->order->buyer_id === auth()->id(), 403);

        $data = $request->validate([
            'reason' => 'required|string|max:120',
            'detail' => 'nullable|string|max:2000',
        ]);

        abort_if(
            ReturnRequest::where('order_item_id', $orderItem->id)
                ->whereIn('status', ['requested', 'approved', 'received'])
                ->exists(),
            422,
            'A return is already in progress for this item.'
        );

        abort_unless(
            in_array($orderItem->status, [OrderStatus::Delivered, OrderStatus::PayoutReleased], true),
            422,
            'Returns can only be started after the item is delivered.'
        );

        // Built only when it applies — returnDeadline() is null for items with no delivery date.
        if (! $orderItem->isWithinReturnWindow()) {
            abort(422, 'The return window for this item closed on '
                .$orderItem->returnDeadline()->format('M j, Y').'. You can still contact the seller or open a claim.');
        }

        $rma = ReturnRequest::create([
            'order_item_id' => $orderItem->id,
            'user_id' => auth()->id(),
            'reason' => $data['reason'],
            'detail' => $data['detail'] ?? null,
            'status' => 'requested',
        ]);

        $orderItem->update(['status' => OrderStatus::ReturnRequested]);
        $orderItem->statusHistory()->create([
            'status' => OrderStatus::ReturnRequested->value,
            'note' => "RMA {$rma->rma_number}: {$data['reason']}",
            'changed_by' => auth()->id(),
        ]);

        $orderItem->seller?->notify(new \App\Notifications\SellerReturnRequested($rma->setRelation('orderItem', $orderItem)));
        app(\App\Services\Integrations\WebhookDispatcher::class)->orderEvent('return.requested', [$orderItem->fresh('order')], [
            'rma_number' => $rma->rma_number, 'reason' => $rma->reason,
        ]);

        return back()->with('success', "Return started — your RMA number is {$rma->rma_number}.");
    }
}
