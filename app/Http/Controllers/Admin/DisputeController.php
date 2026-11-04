<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Admin\Concerns\AdminTools;
use App\Http\Controllers\Controller;
use App\Models\BuyerClaim;
use App\Models\OrderItem;
use App\Models\PolicyViolation;
use App\Services\Orders\BuyerRefundService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DisputeController extends Controller
{
    use AdminTools;

    public function index()
    {
        $items = OrderItem::whereIn('status', [OrderStatus::ReturnRequested, OrderStatus::Disputed])
            ->with('order.buyer', 'seller.sellerProfile', 'product', 'statusHistory')
            ->latest()->paginate(20);

        $claims = BuyerClaim::whereIn('status', ['open', 'seller_responded', 'escalated'])
            ->with('orderItem.order:id,order_number', 'buyer:id,name', 'seller.sellerProfile')
            ->orderByRaw("CASE status WHEN 'escalated' THEN 0 ELSE 1 END")
            ->latest()->get();

        return view('admin.disputes.index', compact('items', 'claims'));
    }

    /**
     * Granting a claim refunds the buyer the way they paid and takes the money back from the seller.
     * It used to add store credit out of nowhere: the platform paid and the seller kept the sale.
     */
    public function decideClaim(Request $request, BuyerClaim $buyerClaim, BuyerRefundService $refunds)
    {
        abort_unless(auth()->user()->can('refunds.issue'), 403);

        $data = $request->validate([
            'decision' => 'required|in:grant,deny',
            'note' => 'nullable|string|max:1000',
        ]);

        if (! in_array($buyerClaim->status, ['open', 'seller_responded', 'escalated'], true)) {
            return back()->withErrors(['claim' => 'This claim has already been decided.']);
        }

        if ($data['decision'] === 'grant') {
            $item = $buyerClaim->orderItem;
            $alreadyRefunded = in_array($item->status, [OrderStatus::Refunded, OrderStatus::Cancelled], true);

            try {
                DB::transaction(function () use ($buyerClaim, $item, $refunds, $alreadyRefunded, $data) {
                    if (! $alreadyRefunded) {
                        $amount = $refunds->refundLine($item, 'claim', "Refund for claim {$buyerClaim->claim_number} on order {$item->order->order_number}", true);

                        $item->update(['status' => OrderStatus::Refunded]);
                        $item->statusHistory()->create([
                            'status' => OrderStatus::Refunded->value,
                            'note' => "Claim {$buyerClaim->claim_number} granted by PanMotive — refunded \$".number_format($amount, 2),
                            'changed_by' => auth()->id(),
                        ]);
                    }

                    $buyerClaim->update(['status' => 'granted', 'decided_by' => auth()->id(), 'decided_at' => now(), 'resolution_note' => $data['note'] ?? null]);

                    PolicyViolation::create([
                        'seller_id' => $buyerClaim->seller_id,
                        'type' => 'claim_granted',
                        'severity' => 'warning',
                        'title' => 'Buyer claim decided against the store',
                        'description' => "Claim {$buyerClaim->claim_number} ({$buyerClaim->reason}) was granted to the buyer.",
                        'order_item_id' => $buyerClaim->order_item_id,
                        'status' => 'active',
                        'issued_by' => auth()->id(),
                        'expires_at' => now()->addDays(180),
                    ]);
                });
            } catch (RuntimeException $e) {
                return back()->withErrors(['claim' => $e->getMessage()]);
            }
        } else {
            $buyerClaim->update(['status' => 'denied', 'decided_by' => auth()->id(), 'decided_at' => now(), 'resolution_note' => $data['note'] ?? null]);
        }

        $this->audit("Claim {$data['decision']}ed", $buyerClaim, $data);
        $buyerClaim->orderItem?->order?->syncStatusFromLines();
        $granted = $data['decision'] === 'grant';
        $this->tellBuyer($buyerClaim->orderItem?->order, $granted ? 'Your claim was approved' : 'A decision on your claim',
            $granted ? "Claim {$buyerClaim->claim_number} was approved and you have been refunded." : "Claim {$buyerClaim->claim_number} was not approved.".(filled($data['note'] ?? null) ? " {$data['note']}" : ''));
        $this->tell($buyerClaim->seller, $granted ? 'A buyer claim was decided against your store' : 'A buyer claim was decided in your favour',
            "Claim {$buyerClaim->claim_number}: ".($granted ? 'the buyer was refunded and this adds a policy violation, which you can appeal.' : 'no money moved.'));

        return back()->with('success', $data['decision'] === 'grant'
            ? "Claim {$buyerClaim->claim_number} granted and the buyer refunded."
            : "Claim {$buyerClaim->claim_number} denied.");
    }

    public function approveReturn(OrderItem $orderItem, BuyerRefundService $refunds)
    {
        abort_unless(auth()->user()->can('refunds.issue'), 403);

        if ($orderItem->payout_released_at) {
            return back()->withErrors(['refund' => 'The seller was already paid. Use "Reverse payout and refund" instead.']);
        }

        try {
            $amount = DB::transaction(function () use ($orderItem, $refunds) {
                $amount = $refunds->refundLine($orderItem, 'return', "Return approved on order {$orderItem->order->order_number}");
                $orderItem->update(['status' => OrderStatus::Refunded]);
                $orderItem->returnRequests()->whereNotIn('status', ['refunded', 'rejected'])->update([
                    'status' => 'refunded', 'resolved_by' => auth()->id(), 'resolved_at' => now(),
                ]);

                return $amount;
            });
        } catch (RuntimeException $e) {
            return back()->withErrors(['refund' => $e->getMessage()]);
        }

        $orderItem->statusHistory()->create([
            'status' => OrderStatus::Refunded->value,
            'note' => 'Return approved and refunded by admin',
            'changed_by' => auth()->id(),
        ]);
        $orderItem->order->syncStatusFromLines();
        $this->audit('Approved return and refunded', $orderItem, ['amount' => $amount]);
        $this->tellBuyer($orderItem->order, 'Your return was approved', 'We refunded $'.number_format($amount, 2)." for {$orderItem->product_title}.");
        $this->tell($orderItem->seller, 'A return was approved by PanMotive', "{$orderItem->product_title} on order {$orderItem->order->order_number} was refunded to the buyer.");

        return back()->with('success', 'Return approved — buyer refunded.');
    }

    public function denyReturn(Request $request, OrderItem $orderItem)
    {
        abort_unless(auth()->user()->can('disputes.resolve'), 403);

        $request->validate(['reason' => 'required|string|max:1000']);

        // Back to where it was before the return, so a released payout isn't made to look unpaid.
        $orderItem->update(['status' => $orderItem->payout_released_at ? OrderStatus::PayoutReleased : OrderStatus::Delivered]);
        $orderItem->statusHistory()->create([
            'status' => 'return_denied',
            'note' => $request->reason,
            'changed_by' => auth()->id(),
        ]);
        $orderItem->returnRequests()->whereNotIn('status', ['refunded', 'rejected'])->update([
            'status' => 'rejected', 'resolution_note' => $request->reason, 'resolved_by' => auth()->id(), 'resolved_at' => now(),
        ]);
        $this->audit('Denied return', $orderItem, ['reason' => $request->reason]);
        $this->tellBuyer($orderItem->order, 'A decision on your return', "Your return of {$orderItem->product_title} was not approved. {$request->reason}");

        return back()->with('success', 'Return denied.');
    }

    public function reverseSellerPayout(Request $request, OrderItem $orderItem, BuyerRefundService $refunds)
    {
        // For the case RefundService flagged: payout already released, needs manual seller-side reversal.
        abort_unless(auth()->user()->can('refunds.issue'), 403);
        abort_unless($orderItem->payout_released_at !== null, 400);

        $request->validate(['reason' => 'required|string|max:1000']);

        // The shared refund path: pulls the payout back, refunds what the buyer actually paid
        // (not the list price), splits card and credit, and reverses cashback.
        try {
            $amount = DB::transaction(function () use ($orderItem, $refunds, $request) {
                $amount = $refunds->refundLine($orderItem, 'dispute', "Refund on order {$orderItem->order->order_number}");
                $orderItem->update(['status' => OrderStatus::Refunded]);
                $orderItem->statusHistory()->create([
                    'status' => 'payout_reversed_and_refunded',
                    'note' => $request->reason,
                    'changed_by' => auth()->id(),
                ]);
                $orderItem->returnRequests()->whereNotIn('status', ['refunded', 'rejected'])->update([
                    'status' => 'refunded', 'resolved_by' => auth()->id(), 'resolved_at' => now(),
                ]);

                return $amount;
            });
        } catch (RuntimeException $e) {
            return back()->withErrors(['refund' => $e->getMessage()]);
        }

        $orderItem->order->syncStatusFromLines();
        $this->tellBuyer($orderItem->order, 'Your refund is on its way', 'We refunded $'.number_format($amount, 2)." for {$orderItem->product_title}.");
        $this->tell($orderItem->seller, 'A payout was reversed', "PanMotive pulled back your payout for {$orderItem->product_title} on order {$orderItem->order->order_number}. Reason: {$request->reason}");
        $this->audit('Reversed payout and refunded', $orderItem, ['reason' => $request->reason]);

        return back()->with('success', 'Seller payout reversed and buyer refunded.');
    }
}
