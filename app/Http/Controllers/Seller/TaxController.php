<?php

namespace App\Http\Controllers\Seller;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Services\Seller\SellerLedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TaxController extends Controller
{
    /** Never paid, or cancelled before it shipped — no money changed hands. */
    private const NOT_PAID = [OrderStatus::Placed, OrderStatus::Cancelled];

    private function year(Request $request): int
    {
        $year = (int) $request->integer('year', now()->year);

        return max(now()->year - 6, min(now()->year, $year));
    }

    public function index(Request $request, SellerLedgerService $ledger)
    {
        $sellerId = auth()->id();
        $year = $this->year($request);
        $from = Carbon::create($year, 1, 1)->startOfDay();
        $to = $year === now()->year ? now() : $from->copy()->endOfYear();

        // 1099-K reports gross payments processed in the year — before fees and refunds.
        $paid = OrderItem::where('seller_id', $sellerId)
            ->whereNotIn('status', collect(self::NOT_PAID)->map->value)
            ->whereBetween('created_at', [$from, $to]);
        $gross = (float) (clone $paid)->sum('line_total');
        $txns = (clone $paid)->distinct('order_id')->count('order_id');

        $byMonth = (clone $paid)->get(['line_total', 'created_at'])
            ->groupBy(fn ($i) => (int) $i->created_at->format('n'))
            ->map(fn ($rows) => (float) $rows->sum('line_total'));
        $months = collect(range(1, 12))->map(fn ($m) => [
            'month' => Carbon::create($year, $m, 1)->format('F'),
            'num' => $m,
            'gross' => (float) ($byMonth[$m] ?? 0),
        ]);

        return view('seller.tax.index', [
            'year' => $year,
            'years' => range(now()->year, now()->year - 4),
            'gross' => $gross,
            'txns' => $txns,
            // Fees, ad spend and refunds for the year, from the same ledger the P&L uses.
            'pnl' => $ledger->pnl($sellerId, $from, $to),
            'months' => $months,
            'threshold' => (float) config('marketplace.tax_1099k_threshold', 20000),
            'minTxns' => (int) config('marketplace.tax_1099k_min_transactions', 200),
        ]);
    }

    /** Month or full-year statement: every sale, fee, refund and ad charge — the ledger for that period. */
    public function statement(Request $request, SellerLedgerService $ledger)
    {
        $year = $this->year($request);
        $month = $request->integer('month');
        $from = $month ? Carbon::create($year, $month, 1)->startOfDay() : Carbon::create($year, 1, 1)->startOfDay();
        $to = $month ? $from->copy()->endOfMonth() : $from->copy()->endOfYear();
        $label = $month ? $from->format('Y-m') : (string) $year;

        return response()->streamDownload(
            fn () => print ($ledger->toCsv(auth()->id(), $from, $to)),
            "panmotive-statement-{$label}.csv",
            ['Content-Type' => 'text/csv'],
        );
    }
}
