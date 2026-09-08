<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinanceController extends Controller
{
    public function ledger(Request $request)
    {
        abort_unless(auth()->user()->can('finance.view'), 403);

        $query = OrderItem::whereIn('status', ['delivered', 'payout_released'])
            ->with('order', 'seller.sellerProfile');

        if ($request->from) {
            $query->where('created_at', '>=', $request->from);
        }
        if ($request->to) {
            $query->where('created_at', '<=', $request->to);
        }
        if ($request->seller_id) {
            $query->where('seller_id', $request->seller_id);
        }

        $entries = (clone $query)->latest()->paginate(50);

        $totals = $query->select(
            DB::raw('SUM(line_total) as gross'),
            DB::raw('SUM(platform_fee_amount) as commission'),
            DB::raw('SUM(seller_payout_amount) as payouts')
        )->first();

        return view('admin.finance.ledger', compact('entries', 'totals'));
    }

    public function exportCsv(Request $request)
    {
        abort_unless(auth()->user()->can('finance.export'), 403);

        $items = OrderItem::whereIn('status', ['delivered', 'payout_released'])
            ->when($request->from, fn ($q) => $q->where('created_at', '>=', $request->from))
            ->when($request->to, fn ($q) => $q->where('created_at', '<=', $request->to))
            ->with('order', 'seller.sellerProfile')->get();

        $filename = 'commission-ledger-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($items) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Order #', 'Seller', 'Line Total', 'Commission Rate', 'Platform Fee', 'Seller Payout', 'Status', 'Date']);

            foreach ($items as $item) {
                fputcsv($handle, [
                    $item->order->order_number,
                    $item->seller->sellerProfile?->business_name ?? '—',
                    $item->line_total,
                    $item->commission_rate.'%',
                    $item->platform_fee_amount,
                    $item->seller_payout_amount,
                    $item->status->label(),
                    $item->created_at->toDateString(),
                ]);
            }
            fclose($handle);
        }, $filename);
    }
}
