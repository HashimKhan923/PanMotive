<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Admin\Concerns\AdminTools;
use App\Http\Controllers\Controller;
use App\Jobs\ReleaseSellerPayout;
use App\Models\AdCreditPurchase;
use App\Models\AdminAlert;
use App\Models\Boost;
use App\Models\CreditTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PayoutAdvance;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FinanceController extends Controller
{
    use AdminTools;

    private const EARNED_EXCLUDED = ['placed', 'cancelled', 'refunded'];

    public function overview(Request $request)
    {
        $from = $request->filled('from') ? $request->date('from')->startOfDay() : now()->startOfMonth();
        $to = $request->filled('to') ? $request->date('to')->endOfDay() : now();

        $lines = OrderItem::whereBetween('created_at', [$from, $to]);
        $earned = (clone $lines)->whereNotIn('status', self::EARNED_EXCLUDED);

        $revenue = [
            'Commission' => (float) (clone $earned)->sum('platform_fee_amount'),
            'Offsite Ads fees' => (float) (clone $earned)->sum('offsite_ad_fee'),
            'Shipping label fees' => (float) (clone $earned)->sum('label_fee'),
            'Instant payout fees' => (float) PayoutAdvance::whereBetween('created_at', [$from, $to])->sum('fee'),
            'Boost fees' => (float) Boost::whereBetween('created_at', [$from, $to])->where('status', '!=', 'cancelled')->sum('fee'),
            'Ad credit bought' => (float) AdCreditPurchase::where('status', 'paid')->whereBetween('paid_at', [$from, $to])->sum('amount'),
        ];

        return view('admin.finance.overview', [
            'from' => $from, 'to' => $to,
            'revenue' => $revenue,
            'totalRevenue' => array_sum($revenue),
            'sales' => (float) Order::whereBetween('created_at', [$from, $to])->whereNotIn('status', ['placed', 'cancelled'])->sum(DB::raw('total + credits_used')),
            'refunded' => (float) OrderItem::where('status', OrderStatus::Refunded)->whereBetween('updated_at', [$from, $to])->sum('line_total'),
            'paidOut' => (float) OrderItem::whereBetween('payout_released_at', [$from, $to])->sum('seller_payout_amount'),
            // What PanMotive holds that belongs to someone else, right now.
            'owedSellers' => (float) OrderItem::whereNull('payout_released_at')
                ->whereIn('status', [OrderStatus::Paid, OrderStatus::SellerAccepted, OrderStatus::Shipped, OrderStatus::OutForDelivery, OrderStatus::Delivered])->sum('seller_payout_amount'),
            'walletOwed' => (float) User::sum('wallet_balance'),
            'adCreditOwed' => (float) SellerProfile::sum('ad_credit'),
            'monthly' => $this->monthly(),
            'taxCollecting' => (bool) config('marketplace.sales_tax.collecting'),
        ]);
    }

    /** @return array{labels: list<string>, commission: list<float>} the last 12 months, empty months included */
    private function monthly(): array
    {
        $labels = [];
        $commission = [];
        foreach (range(11, 0) as $ago) {
            $start = now()->startOfMonth()->subMonths($ago);
            $labels[] = $start->format('M y');
            $commission[] = round((float) OrderItem::whereBetween('created_at', [$start, $start->copy()->endOfMonth()])
                ->whereNotIn('status', self::EARNED_EXCLUDED)->sum('platform_fee_amount'), 2);
        }

        return compact('labels', 'commission');
    }

    public function ledger(Request $request)
    {
        $query = $this->ledgerQuery($request)->with('order', 'seller.sellerProfile');

        $entries = (clone $query)->latest()->paginate(50)->withQueryString();

        $totals = $query->reorder()->select(
            DB::raw('SUM(line_total) as gross'),
            DB::raw('SUM(platform_fee_amount) as commission'),
            DB::raw('SUM(seller_payout_amount) as payouts')
        )->without('order', 'seller')->first();

        return view('admin.finance.ledger', [
            'entries' => $entries, 'totals' => $totals,
            'sellers' => SellerProfile::orderBy('business_name')->get(['user_id', 'business_name']),
        ]);
    }

    public function exportCsv(Request $request)
    {
        abort_unless(auth()->user()->can('finance.export'), 403);

        $items = $this->ledgerQuery($request)->with('order', 'seller.sellerProfile')->get();

        $filename = 'commission-ledger-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($items) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Order #', 'Seller', 'Line Total', 'Commission Rate', 'Platform Fee', 'Seller Payout', 'Status', 'Date']);

            foreach ($items as $item) {
                fputcsv($handle, array_map(fn ($v) => $this->csvCell($v), [
                    $item->order->order_number,
                    $item->seller->sellerProfile?->business_name ?? '—',
                    $item->line_total,
                    $item->commission_rate.'%',
                    $item->platform_fee_amount,
                    $item->seller_payout_amount,
                    $item->status->label(),
                    $item->created_at->toDateString(),
                ]));
            }
            fclose($handle);
        }, $filename);
    }

    private function ledgerQuery(Request $request)
    {
        return OrderItem::whereIn('status', ['delivered', 'payout_released'])
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->when($request->filled('seller_id'), fn ($q) => $q->where('seller_id', $request->integer('seller_id')));
    }

    public function payouts(Request $request)
    {
        $tab = $request->query('tab', 'ready');

        $query = OrderItem::with('order:id,order_number', 'seller.sellerProfile')
            ->when($tab === 'ready', fn ($q) => $q->where('status', OrderStatus::Delivered)->whereNull('payout_released_at'))
            ->when($tab === 'released', fn ($q) => $q->whereNotNull('payout_released_at'))
            ->when($request->filled('q'), fn ($q) => $q->whereHas('seller.sellerProfile', fn ($s) => $s->where('business_name', 'like', $this->like($request->q))));

        $query->orderByDesc($tab === 'released' ? 'payout_released_at' : 'delivered_at');

        $ready = OrderItem::where('status', OrderStatus::Delivered)->whereNull('payout_released_at');

        return view('admin.finance.payouts', [
            'tab' => $tab,
            'lines' => $query->paginate(25)->withQueryString(),
            'tabs' => [
                'ready' => ['Delivered, not paid', (clone $ready)->count()],
                'released' => ['Paid out', OrderItem::whereNotNull('payout_released_at')->count()],
            ],
            'readyTotal' => (float) (clone $ready)->sum('seller_payout_amount'),
            'releasedTotal' => (float) OrderItem::whereNotNull('payout_released_at')->sum('seller_payout_amount'),
            'held' => SellerProfile::where('payouts_held', true)->get(['id', 'slug', 'business_name', 'payouts_held_reason']),
            'blocked' => AdminAlert::whereIn('type', ['payout_blocked', 'payout_held'])->whereNull('resolved_at')->count(),
        ]);
    }

    /** Try a stuck payout again. The job refuses to pay twice and still respects holds. */
    public function retryPayout(OrderItem $orderItem)
    {
        if ($orderItem->status !== OrderStatus::Delivered || $orderItem->payout_released_at) {
            return back()->with('error', 'This line is not waiting on a payout.');
        }

        ReleaseSellerPayout::dispatch($orderItem->id);
        $this->audit('Retried seller payout', $orderItem);

        return back()->with('success', 'Payout queued. Check Alerts if it is blocked again.');
    }

    public function wallets(Request $request)
    {
        return view('admin.finance.wallets', [
            'transactions' => CreditTransaction::with('user:id,name,email')
                ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
                ->when($request->filled('q'), fn ($q) => $q->whereHas('user', fn ($u) => $u->where('email', 'like', $this->like($request->q))->orWhere('name', 'like', $this->like($request->q))))
                ->latest()->paginate(30)->withQueryString(),
            'walletOwed' => (float) User::sum('wallet_balance'),
            'holders' => User::where('wallet_balance', '>', 0)->count(),
            'granted' => (float) CreditTransaction::where('amount', '>', 0)->where('created_at', '>=', now()->subDays(30))->sum('amount'),
            'redeemed' => abs((float) CreditTransaction::where('type', 'redeemed')->where('created_at', '>=', now()->subDays(30))->sum('amount')),
            'types' => ['cashback' => 'Cashback', 'referral_bonus' => 'Referral bonus', 'redeemed' => 'Spent at checkout', 'admin_adjustment' => 'Staff and refunds', 'refund_reversal' => 'Cashback reversed'],
        ]);
    }

    /** Who needs a 1099-K for a year, using the same definition sellers see on their own tax page. */
    public function tax(Request $request)
    {
        $year = in_array($request->integer('year'), range(now()->year, now()->year - 4), true) ? $request->integer('year') : now()->year;
        $from = Carbon::create($year, 1, 1)->startOfDay();
        $to = $from->copy()->endOfYear();

        $threshold = (float) config('marketplace.tax_1099k_threshold', 20000);
        $minTxns = (int) config('marketplace.tax_1099k_min_transactions', 200);

        $rows = OrderItem::whereBetween('order_items.created_at', [$from, $to])
            ->whereNotIn('order_items.status', ['placed', 'cancelled'])
            ->join('seller_profiles', 'seller_profiles.user_id', '=', 'order_items.seller_id')
            ->selectRaw('seller_profiles.business_name AS name, seller_profiles.slug AS slug, SUM(order_items.line_total) AS gross, COUNT(DISTINCT order_items.order_id) AS txns')
            ->groupBy('name', 'slug')->orderByDesc('gross')->get()
            ->each(fn ($r) => $r->reportable = (float) $r->gross > $threshold && (int) $r->txns > $minTxns);

        return view('admin.finance.tax', [
            'rows' => $rows, 'year' => $year, 'years' => range(now()->year, now()->year - 4),
            'threshold' => $threshold, 'minTxns' => $minTxns,
        ]);
    }
}
