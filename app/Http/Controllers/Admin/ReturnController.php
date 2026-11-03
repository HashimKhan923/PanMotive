<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AdminTools;
use App\Http\Controllers\Controller;
use App\Models\ReturnRequest;
use App\Services\Orders\ReturnService;
use Illuminate\Http\Request;
use RuntimeException;

class ReturnController extends Controller
{
    use AdminTools;

    public function index(Request $request)
    {
        $tab = $request->query('status', 'escalated');
        $counts = ReturnRequest::selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status');

        $returns = ReturnRequest::with('orderItem.order:id,order_number', 'orderItem.seller.sellerProfile', 'user:id,name', 'events')
            ->when($tab === 'escalated', fn ($q) => $q->whereNotNull('escalated_at')->whereNull('resolved_at'))
            ->when(! in_array($tab, ['escalated', 'all'], true), fn ($q) => $q->where('status', $tab))
            ->when($request->filled('q'), fn ($q) => $q->where('rma_number', 'like', $this->like($request->q)))
            ->latest()->paginate(20)->withQueryString();

        return view('admin.returns.index', [
            'returns' => $returns, 'tab' => $tab,
            'tabs' => [
                'escalated' => ['Escalated to us', ReturnRequest::whereNotNull('escalated_at')->whereNull('resolved_at')->count()],
                'requested' => ['Waiting on seller', $counts['requested'] ?? 0],
                'approved' => ['Approved', $counts['approved'] ?? 0],
                'received' => ['Received', $counts['received'] ?? 0],
                'refunded' => ['Refunded', $counts['refunded'] ?? 0],
                'rejected' => ['Rejected', $counts['rejected'] ?? 0],
                'all' => ['All', $counts->sum()],
            ],
            'service' => app(ReturnService::class),
        ]);
    }

    public function act(Request $request, ReturnRequest $returnRequest, ReturnService $returns)
    {
        $data = $request->validate(['action' => 'required|in:approve,reject,refund,refund_without_return', 'note' => 'nullable|string|max:1000']);

        $moves = in_array($data['action'], ['refund', 'refund_without_return'], true);
        abort_unless($request->user()->can($moves ? 'refunds.issue' : 'disputes.resolve'), 403);

        try {
            $refunded = $returns->act($returnRequest, $data['action'], $request->user(), $data['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withErrors(['return' => $e->getMessage()]);
        }

        $this->audit("Return {$data['action']}", $returnRequest, $data + ['refunded' => $refunded]);

        return back()->with('success', $refunded !== null ? '$'.number_format($refunded, 2).' refunded to the buyer.' : 'Return updated.');
    }
}
