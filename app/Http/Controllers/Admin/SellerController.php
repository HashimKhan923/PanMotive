<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Admin\Concerns\AdminTools;
use App\Http\Controllers\Controller;
use App\Jobs\ReleaseSellerPayout;
use App\Models\AdminAlert;
use App\Models\BuyerClaim;
use App\Models\OrderItem;
use App\Models\PolicyViolation;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\SellerVerification;
use App\Notifications\AccountNotice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SellerController extends Controller
{
    use AdminTools;

    public function index(Request $request)
    {
        $status = $request->query('status', '');
        $counts = SellerProfile::selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status');

        $sellers = SellerProfile::with('user:id,name,email,is_active')
            ->when($status === 'held', fn ($q) => $q->where('payouts_held', true))
            ->when($status && $status !== 'held', fn ($q) => $q->where('status', $status))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                ->where('business_name', 'like', $this->like($request->q))
                ->orWhereHas('user', fn ($u) => $u->where('email', 'like', $this->like($request->q)))))
            ->when($request->filled('tier'), fn ($q) => $q->where('tier', $request->tier))
            ->latest()->paginate(25)->withQueryString();

        $ids = $sellers->pluck('user_id');
        $sales = OrderItem::whereIn('seller_id', $ids)->whereNotIn('status', ['placed', 'cancelled', 'refunded'])
            ->selectRaw('seller_id, SUM(line_total) AS total')->groupBy('seller_id')->pluck('total', 'seller_id');
        $listings = Product::whereIn('seller_id', $ids)->where('status', 'active')
            ->selectRaw('seller_id, COUNT(*) AS c')->groupBy('seller_id')->pluck('c', 'seller_id');

        return view('admin.sellers.index', [
            'sellers' => $sellers, 'sales' => $sales, 'listings' => $listings, 'status' => $status,
            'tabs' => [
                '' => ['All', $counts->sum()],
                'pending' => ['Applications', $counts['pending'] ?? 0],
                'approved' => ['Approved', $counts['approved'] ?? 0],
                'suspended' => ['Suspended', $counts['suspended'] ?? 0],
                'rejected' => ['Rejected', $counts['rejected'] ?? 0],
                'held' => ['Payouts on hold', SellerProfile::where('payouts_held', true)->count()],
            ],
            'tiers' => config('marketplace.seller_tiers'),
        ]);
    }

    public function show(SellerProfile $sellerProfile)
    {
        $sellerProfile->load('user');
        $sellerId = $sellerProfile->user_id;
        $lines = OrderItem::where('seller_id', $sellerId);
        $earned = (clone $lines)->whereNotIn('status', ['placed', 'cancelled', 'refunded']);

        return view('admin.sellers.show', [
            'profile' => $sellerProfile,
            'stats' => [
                'sales' => (float) (clone $earned)->sum('line_total'),
                'commission' => (float) (clone $earned)->sum('platform_fee_amount'),
                'orders' => (clone $earned)->count(),
                'refunded' => (clone $lines)->where('status', OrderStatus::Refunded)->count(),
                'unpaid' => (float) (clone $lines)->where('status', OrderStatus::Delivered)->whereNull('payout_released_at')->sum('seller_payout_amount'),
                'to_ship' => (clone $lines)->whereIn('status', [OrderStatus::Paid, OrderStatus::SellerAccepted])->count(),
                'live_listings' => Product::listed()->where('seller_id', $sellerId)->count(),
                'all_listings' => Product::where('seller_id', $sellerId)->count(),
            ],
            'recentLines' => (clone $lines)->with('order:id,order_number')->latest()->take(8)->get(),
            'violations' => PolicyViolation::where('seller_id', $sellerId)->latest()->take(10)->get(),
            'claims' => BuyerClaim::where('seller_id', $sellerId)->latest()->take(5)->get(),
            'verification' => SellerVerification::where('seller_id', $sellerId)->latest('submitted_at')->first(),
            'tiers' => config('marketplace.seller_tiers'),
            'defaultRate' => (float) config('marketplace.default_commission_rate'),
            'score' => app(\App\Services\Seller\SellerScorecardService::class)->metrics($sellerProfile->user),
            'history' => \Spatie\Activitylog\Models\Activity::where('subject_type', $sellerProfile->getMorphClass())->where('subject_id', $sellerProfile->id)->with('causer')->latest()->take(10)->get(),
        ]);
    }

    /** A rate for this store only. It beats tier rates and applies to future orders, never past ones. */
    public function commission(Request $request, SellerProfile $sellerProfile)
    {
        $data = $request->validate(['rate' => 'nullable|numeric|min:0|max:50', 'reason' => 'required|string|max:255']);
        $rate = filled($data['rate'] ?? null) ? round((float) $data['rate'], 2) : null;

        $old = $sellerProfile->commission_rate_override;
        $sellerProfile->forceFill(['commission_rate_override' => $rate])->save();
        $this->audit('Changed seller commission', $sellerProfile, ['from' => $old, 'to' => $rate, 'reason' => $data['reason']]);

        return back()->with('success', $rate === null ? 'Back to the standard commission.' : "Commission set to {$rate}% for future orders.");
    }

    public function tier(Request $request, SellerProfile $sellerProfile)
    {
        $data = $request->validate([
            'tier' => ['required', Rule::in(array_keys(config('marketplace.seller_tiers')))],
            'locked' => 'nullable|boolean',
            'reason' => 'required|string|max:255',
        ]);

        $old = $sellerProfile->tier;
        $sellerProfile->forceFill(['tier' => $data['tier'], 'tier_locked' => (bool) ($data['locked'] ?? false)])->save();
        $this->audit('Changed seller tier', $sellerProfile, ['from' => $old] + $data);

        return back()->with('success', 'Tier updated.');
    }

    public function payoutHold(Request $request, SellerProfile $sellerProfile)
    {
        $data = $request->validate(['hold' => 'required|boolean', 'reason' => 'required|string|max:255']);
        $hold = (bool) $data['hold'];

        $sellerProfile->forceFill(['payouts_held' => $hold, 'payouts_held_reason' => $hold ? $data['reason'] : null])->save();
        $this->audit($hold ? 'Placed payouts on hold' : 'Released payout hold', $sellerProfile, ['reason' => $data['reason']]);

        $sellerProfile->user->notify(new AccountNotice(
            $hold ? 'Your payouts are on hold' : 'Your payouts are active again',
            $hold ? "Panmotive has paused your payouts. Reason: {$data['reason']}. Your earnings are safe and you can contact Help & support." : 'The hold on your payouts has been lifted. Delivered orders will be paid out as normal.'
        ));

        if (! $hold) {
            $waiting = OrderItem::where('seller_id', $sellerProfile->user_id)->where('status', OrderStatus::Delivered)->whereNull('payout_released_at')->pluck('id');
            AdminAlert::where('type', 'payout_held')->whereNull('resolved_at')->whereIn('related_order_item_id', $waiting)->update(['resolved_at' => now()]);
            $waiting->each(fn ($id) => ReleaseSellerPayout::dispatch($id));
        }

        return back()->with('success', $hold ? 'Payouts are on hold.' : 'Payout hold released. Waiting payouts are on their way.');
    }

    public function notes(Request $request, SellerProfile $sellerProfile)
    {
        $data = $request->validate(['notes' => 'nullable|string|max:5000']);
        $sellerProfile->forceFill(['admin_notes' => $data['notes'] ?? null])->save();

        return back()->with('success', 'Notes saved.');
    }

    public function violation(Request $request, SellerProfile $sellerProfile)
    {
        $data = $request->validate([
            'type' => 'required|string|max:60',
            'severity' => 'required|in:notice,warning,severe',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
        ]);

        $violation = PolicyViolation::create($data + [
            'seller_id' => $sellerProfile->user_id, 'status' => 'active',
            'issued_by' => auth()->id(), 'expires_at' => now()->addDays(180),
        ]);

        DB::afterCommit(fn () => $sellerProfile->user->notify(new AccountNotice(
            'A policy issue was added to your account', "{$data['title']}. You can see it and appeal in Account health."
        )));
        $this->audit('Issued policy violation', $violation, $data);

        return back()->with('success', 'Violation issued. The seller can see and appeal it.');
    }
}
