<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AdminTools;
use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\AffiliateConversion;
use App\Models\MarketingAutomation;
use App\Models\MarketingCampaign;
use App\Models\Subscription;
use App\Services\Marketing\AffiliateService;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Http\Request;

/** Things sellers and buyers run themselves, which staff can see and stop when something's wrong. */
class OversightController extends Controller
{
    use AdminTools;

    // ---------- Subscribe & Save ----------

    public function subscriptions(Request $request)
    {
        $status = $request->query('status', 'active');
        $counts = Subscription::selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status');

        return view('admin.oversight.subscriptions', [
            'subscriptions' => Subscription::with('buyer:id,name,email', 'seller.sellerProfile', 'product:id,title,price,sale_price,sale_starts_at,deal_ends_at')
                ->when($status !== 'all', fn ($q) => $q->where('status', $status))
                ->when($request->filled('q'), fn ($q) => $q->whereHas('buyer', fn ($b) => $b->where('email', 'like', $this->like($request->q))->orWhere('name', 'like', $this->like($request->q))))
                ->orderBy('next_run_at')->paginate(25)->withQueryString(),
            'status' => $status,
            'tabs' => [
                'active' => ['Active', $counts['active'] ?? 0], 'paused' => ['Paused', $counts['paused'] ?? 0],
                'cancelled' => ['Cancelled', $counts['cancelled'] ?? 0], 'all' => ['All', $counts->sum()],
            ],
            'dueSoon' => Subscription::where('status', 'active')->where('next_run_at', '<=', now()->addDays(7))->count(),
            'ordersMade' => (int) Subscription::sum('orders_count'),
        ]);
    }

    public function subscriptionAction(Request $request, Subscription $subscription, SubscriptionService $service)
    {
        abort_unless($request->user()->can('disputes.resolve'), 403);

        $data = $request->validate(['action' => 'required|in:pause,resume,cancel', 'reason' => 'required|string|max:255']);

        if ($subscription->status === 'cancelled') {
            return back()->with('error', 'This subscription is already cancelled.');
        }

        match ($data['action']) {
            'pause' => $service->pause($subscription),
            'resume' => $service->resume($subscription),
            'cancel' => $service->cancel($subscription),
        };
        $this->audit("Subscription {$data['action']}", $subscription, $data);
        $this->tell($subscription->buyer, 'Your Subscribe & Save was changed',
            'PanMotive '.['pause' => 'paused', 'resume' => 'resumed', 'cancel' => 'cancelled'][$data['action']].' your repeat order for '.($subscription->product?->title ?? 'a part').'. Reason: '.$data['reason'].'.');

        return back()->with('success', 'Subscription updated.');
    }

    // ---------- Affiliates ----------

    public function affiliates(Request $request)
    {
        $status = $request->query('status', '');
        $counts = Affiliate::selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status');

        return view('admin.oversight.affiliates', [
            'affiliates' => Affiliate::with('program.seller.sellerProfile')
                ->when($status, fn ($q) => $q->where('status', $status))
                ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $this->like($request->q))->orWhere('email', 'like', $this->like($request->q))))
                ->orderByDesc('earnings')->paginate(25)->withQueryString(),
            'conversions' => AffiliateConversion::with('affiliate:id,name,code', 'order:id,order_number')->where('status', 'pending')->latest()->take(15)->get(),
            'status' => $status,
            'tabs' => ['' => ['All', $counts->sum()], 'pending' => ['Pending', $counts['pending'] ?? 0], 'approved' => ['Approved', $counts['approved'] ?? 0], 'rejected' => ['Removed', $counts['rejected'] ?? 0]],
            'stats' => [
                'earned' => (float) Affiliate::sum('earnings'),
                'paid' => (float) Affiliate::sum('paid_out'),
                'clicks' => (int) Affiliate::sum('clicks'),
            ],
        ]);
    }

    public function affiliateStatus(Request $request, Affiliate $affiliate)
    {
        $data = $request->validate(['status' => 'required|in:approved,rejected', 'reason' => 'required|string|max:255']);

        $affiliate->update(['status' => $data['status']]);
        $this->audit($data['status'] === 'rejected' ? 'Removed affiliate' : 'Approved affiliate', $affiliate, $data);

        return back()->with('success', $data['status'] === 'rejected' ? 'Affiliate removed. Their links stop earning.' : 'Affiliate approved.');
    }

    /** For fraud, like an affiliate buying through their own link. Only unpaid, unapproved commission. */
    public function voidConversion(Request $request, AffiliateConversion $conversion, AffiliateService $service)
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);

        if ($conversion->status !== 'pending') {
            return back()->with('error', 'Only pending commission can be reversed.');
        }

        $service->void($conversion);
        $this->audit('Reversed affiliate commission', $conversion, $data);

        return back()->with('success', 'Commission reversed.');
    }

    // ---------- Seller marketing ----------

    public function campaigns(Request $request)
    {
        $status = $request->query('status', '');
        $counts = MarketingCampaign::selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status');

        return view('admin.oversight.campaigns', [
            'campaigns' => MarketingCampaign::with('seller.sellerProfile')
                ->when($status, fn ($q) => $q->where('status', $status))
                ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $this->like($request->q))->orWhere('subject', 'like', $this->like($request->q))))
                ->latest()->paginate(20)->withQueryString(),
            'automations' => MarketingAutomation::with('seller.sellerProfile')->where('is_active', true)->latest()->take(20)->get(),
            'status' => $status,
            'tabs' => ['' => ['All', $counts->sum()], 'scheduled' => ['Scheduled', $counts['scheduled'] ?? 0], 'sent' => ['Sent', $counts['sent'] ?? 0], 'draft' => ['Draft', $counts['draft'] ?? 0], 'failed' => ['Failed', $counts['failed'] ?? 0]],
        ]);
    }

    /** Stops a scheduled send before it goes out. It returns to the seller as a draft. */
    public function stopCampaign(Request $request, MarketingCampaign $campaign)
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);

        if ($campaign->status !== 'scheduled') {
            return back()->with('error', 'Only scheduled campaigns can be stopped.');
        }

        $campaign->update(['status' => 'draft', 'scheduled_at' => null]);
        $this->audit('Stopped seller campaign', $campaign, $data);
        $this->tell($campaign->seller, 'Your campaign was stopped', "PanMotive stopped \"{$campaign->name}\" before it sent. Reason: {$data['reason']}. It is back in your drafts.");

        return back()->with('success', 'Campaign stopped. It is back in the seller\'s drafts.');
    }

    public function stopAutomation(Request $request, MarketingAutomation $automation)
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);

        $automation->update(['is_active' => false]);
        $this->audit('Stopped seller automation', $automation, $data);
        $this->tell($automation->seller, 'An automation was switched off', "PanMotive switched off one of your marketing automations. Reason: {$data['reason']}.");

        return back()->with('success', 'Automation switched off.');
    }
}
