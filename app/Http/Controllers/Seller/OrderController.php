<?php

namespace App\Http\Controllers\Seller;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $items = OrderItem::where('seller_id', auth()->id())
            ->with('order', 'product')
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(20);

        return view('seller.orders.index', compact('items'));
    }

    public function accept(OrderItem $orderItem)
    {
        $this->authorizeOwnership($orderItem);

        $orderItem->update(['status' => OrderStatus::SellerAccepted]);
        $this->logHistory($orderItem, OrderStatus::SellerAccepted);

        return back()->with('success', 'Order accepted.');
    }

    public function ship(Request $request, OrderItem $orderItem)
    {
        $this->authorizeOwnership($orderItem);

        $validated = $request->validate([
            'carrier' => 'required|string',
            'tracking_number' => 'required|string',
        ]);

        $orderItem->update([
            ...$validated,
            'status' => OrderStatus::Shipped,
            'shipped_at' => now(),
        ]);

        $this->logHistory($orderItem, OrderStatus::Shipped);

        // Phase 4 hooks in here: kick off carrier tracking webhook subscription
        // so delivery status flows in automatically instead of relying only
        // on buyer confirmation / auto-confirm timer.

        return back()->with('success', 'Marked as shipped — tracking sent to buyer.');
    }

    protected function authorizeOwnership(OrderItem $orderItem): void
    {
        abort_unless($orderItem->seller_id === auth()->id(), 403);
    }

    protected function logHistory(OrderItem $orderItem, OrderStatus $status): void
    {
        $orderItem->statusHistory()->create([
            'status' => $status->value,
            'changed_by' => auth()->id(),
        ]);
    }
}
