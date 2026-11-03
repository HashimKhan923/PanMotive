<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Subscription;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $subscriptions) {}

    public function index()
    {
        $subs = Subscription::where('buyer_id', auth()->id())
            ->with(['product.brand', 'variant', 'seller.sellerProfile'])
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 ELSE 2 END")
            ->orderBy('next_run_at')
            ->get();

        return view('storefront.subscriptions', ['subscriptions' => $subs]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'product_variant_id' => 'nullable|exists:product_variants,id',
            'quantity' => 'required|integer|min:1|max:20',
            'interval_days' => 'required|integer|in:'.implode(',', array_keys(Subscription::INTERVALS)),
        ]);

        $product = Product::listed()->findOrFail($data['product_id']);
        abort_unless($product->subscription_enabled, 422, 'This seller does not offer Subscribe & Save on this part.');

        $existing = Subscription::where('buyer_id', auth()->id())
            ->where('product_id', $product->id)
            ->where('status', '!=', 'cancelled')
            ->first();

        if ($existing) {
            return redirect()->route('subscriptions.index')
                ->with('success', 'You already have a subscription for this part — manage it below.');
        }

        $this->subscriptions->create(auth()->user(), $product, [
            'product_variant_id' => $data['product_variant_id'] ?? null,
            'quantity' => $data['quantity'],
            'interval_days' => $data['interval_days'],
            'discount_pct' => (float) $product->subscription_discount_pct,
        ]);

        return redirect()->route('subscriptions.index')
            ->with('success', 'Subscription created — first delivery in '.$data['interval_days'].' days. Cancel or skip anytime.');
    }

    public function update(Request $request, Subscription $subscription)
    {
        abort_unless($subscription->buyer_id === auth()->id(), 403);

        $action = $request->validate([
            'action' => 'required|in:pause,resume,cancel,skip,schedule',
            'interval_days' => 'required_if:action,schedule|integer|in:'.implode(',', array_keys(Subscription::INTERVALS)),
        ]);

        match ($action['action']) {
            'pause' => $this->subscriptions->pause($subscription),
            'resume' => $this->subscriptions->resume($subscription),
            'cancel' => $this->subscriptions->cancel($subscription),
            'skip' => $this->subscriptions->skipNext($subscription),
            'schedule' => $this->subscriptions->setSchedule($subscription, (int) $action['interval_days']),
        };

        return back()->with('success', 'Subscription updated.');
    }
}
