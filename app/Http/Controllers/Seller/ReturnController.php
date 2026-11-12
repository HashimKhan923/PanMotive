<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\ReturnRequest;
use App\Services\Orders\BuyerRefundService;
use App\Services\Orders\ReturnService;
use App\Support\SellerContext;
use Illuminate\Http\Request;
use RuntimeException;

class ReturnController extends Controller
{
    public function index(Request $request, BuyerRefundService $refunds)
    {
        $sellerId = auth()->id();
        $filter = $request->string('status')->toString() ?: 'open';
        $mine = fn () => ReturnRequest::whereHas('orderItem', fn ($q) => $q->where('seller_id', $sellerId));

        $returns = $mine()
            ->with(['orderItem.order:id,order_number,buyer_id', 'orderItem.order.buyer:id,name', 'events.actor:id,name'])
            ->when($filter === 'open', fn ($q) => $q->whereIn('status', ['requested', 'approved', 'received']))
            ->when($filter === 'closed', fn ($q) => $q->whereIn('status', ['rejected', 'refunded']))
            ->when($filter === 'escalated', fn ($q) => $q->whereNotNull('escalated_at'))
            ->orderByRaw('escalated_at IS NULL, respond_by ASC')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        // Insight: how often things come back, why, and which listings are the problem.
        $since = now()->subDays(90);
        $sold = OrderItem::where('seller_id', $sellerId)->where('created_at', '>=', $since)
            ->whereNotIn('status', ['placed', 'cancelled'])->count();
        $returned = $mine()->where('created_at', '>=', $since)->count();

        return view('seller.returns.index', [
            'returns' => $returns,
            'filter' => $filter,
            'openCount' => $mine()->whereIn('status', ['requested', 'approved', 'received'])->count(),
            'overdueCount' => $mine()->where('status', 'requested')->whereNull('escalated_at')->where('respond_by', '<', now())->count(),
            'returnRate' => $sold ? round($returned / $sold * 100, 1) : 0.0,
            'topReasons' => $mine()->where('created_at', '>=', $since)->selectRaw('reason, count(*) as c')
                ->groupBy('reason')->orderByDesc('c')->limit(4)->pluck('c', 'reason'),
            'topProducts' => $mine()->where('return_requests.created_at', '>=', $since)
                ->join('order_items', 'order_items.id', '=', 'return_requests.order_item_id')
                ->selectRaw('order_items.product_title, count(*) as c')->groupBy('order_items.product_title')
                ->orderByDesc('c')->limit(4)->pluck('c', 'product_title'),
            'refundPreview' => fn (ReturnRequest $r) => $refunds->paidForLine($r->orderItem),
            'fitRecord' => app(\App\Services\FitGuarantee::class)->sellerRecord($sellerId),
        ]);
    }

    public function update(Request $request, ReturnRequest $returnRequest, ReturnService $returns)
    {
        abort_unless($returnRequest->orderItem->seller_id === auth()->id(), 403);

        $data = $request->validate([
            'decision' => 'required|in:approve,reject,receive,refund,refund_without_return',
            'note' => 'nullable|string|max:1000',
            'restock' => 'sometimes|boolean',
        ]);

        try {
            $refunded = $returns->act(
                $returnRequest,
                $data['decision'],
                SellerContext::actor() ?? auth()->user(),
                $data['note'] ?? null,
                $request->boolean('restock'),
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['return' => $e->getMessage()]);
        }

        return back()->with('success', match ($data['decision']) {
            'approve' => 'Return approved — the buyer has your instructions.',
            'reject' => $returnRequest->fit_guarantee
                ? 'This is a Fit Guarantee return, so Panmotive will review it and decide. We’ll let you know.'
                : 'Return declined — the buyer has been told why.',
            'receive' => 'Marked as received'.($request->boolean('restock') ? ' and put back in stock.' : '.'),
            default => '$'.number_format((float) $refunded, 2).' refunded to the buyer.',
        });
    }
}
