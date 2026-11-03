<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Services\Seller\SellerLedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class FinanceController extends Controller
{
    public function __construct(private SellerLedgerService $ledger) {}

    private function window(Request $request): array
    {
        $preset = $request->get('period', '30d');
        [$from, $to] = match ($preset) {
            'mtd' => [now()->startOfMonth(), now()],
            'ytd' => [now()->startOfYear(), now()],
            '7d' => [now()->subDays(6)->startOfDay(), now()],
            '90d' => [now()->subDays(89)->startOfDay(), now()],
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            default => [now()->subDays(29)->startOfDay(), now()],
        };

        return [$preset, Carbon::parse($from), Carbon::parse($to)];
    }

    public function ledger(Request $request)
    {
        $sellerId = auth()->id();
        [$period, $from, $to] = $this->window($request);

        return view('seller.finance.ledger', [
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'pnl' => $this->ledger->pnl($sellerId, $from, $to),
            'entries' => $this->ledger->entries($sellerId, $from, $to)->take(300),
            'payouts' => $this->ledger->releasedPayouts($sellerId, 50),
        ]);
    }

    public function export(Request $request)
    {
        $sellerId = auth()->id();
        [$period, $from, $to] = $this->window($request);

        $filename = 'panmotive-ledger-'.$from->toDateString().'-to-'.$to->toDateString().'.csv';

        return response()->streamDownload(
            fn () => print ($this->ledger->toCsv($sellerId, $from, $to)),
            $filename,
            ['Content-Type' => 'text/csv'],
        );
    }
}
