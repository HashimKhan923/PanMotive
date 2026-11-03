<?php

namespace App\Livewire\Seller;

use App\Models\Subscription;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Subscribe & Save subscribers on this store's listings, plus a stock forecast for upcoming repeat orders. */
#[Layout('layouts.seller')]
class Subscriptions extends Component
{
    use WithPagination;

    #[Url]
    public string $status = 'active';

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $sellerId = auth()->id();
        $base = Subscription::where('seller_id', $sellerId);

        $active = (clone $base)->where('status', 'active')->with('product', 'variant')->get();

        // Monthly recurring revenue: each subscription's order value normalised to 30 days.
        $mrr = $active->sum(fn ($s) => $s->unitPrice() * $s->quantity * 30 / max(1, $s->interval_days));

        // What has to ship in the next 14 days, against what's on the shelf.
        $forecast = $active->filter(fn ($s) => $s->next_run_at && $s->next_run_at->lte(now()->addDays(14)))
            ->groupBy(fn ($s) => $s->product_id.'-'.($s->product_variant_id ?? 0))
            ->map(function ($group) {
                $s = $group->first();
                $onHand = $s->variant ? (int) $s->variant->quantity : (int) ($s->product?->quantity ?? 0);
                $needed = (int) $group->sum('quantity');

                return [
                    'title' => $s->product?->title.($s->variant ? ' — '.$s->variant->label : ''),
                    'product_id' => $s->product_id,
                    'deliveries' => $group->count(),
                    'needed' => $needed,
                    'on_hand' => $onHand,
                    'short' => max(0, $needed - $onHand),
                    'next' => $group->min('next_run_at'),
                ];
            })
            ->sortBy('next')->values();

        $counts = [
            'active' => $active->count(),
            'paused' => (clone $base)->where('status', 'paused')->count(),
            'cancelled' => (clone $base)->where('status', 'cancelled')->count(),
        ];

        $subscriptions = (clone $base)
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->with('buyer:id,name', 'product:id,title,slug', 'variant:id,label')
            ->orderByRaw('next_run_at IS NULL, next_run_at ASC')
            ->paginate(20);

        return view('livewire.seller.subscriptions', [
            'subscriptions' => $subscriptions,
            'counts' => $counts,
            'mrr' => round($mrr, 2),
            'forecast' => $forecast,
            'churn30' => (clone $base)->where('status', 'cancelled')->where('updated_at', '>=', now()->subDays(30))->count(),
        ]);
    }
}
