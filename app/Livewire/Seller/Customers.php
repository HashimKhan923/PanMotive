<?php

namespace App\Livewire\Seller;

use App\Models\SellerCustomerNote;
use App\Services\Seller\CustomerInsightsService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.seller')]
class Customers extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $segment = '';

    #[Url]
    public string $tag = '';

    #[Url]
    public string $sort = 'last_order';

    private const SORTS = [
        'last_order' => ['cs.last_order_at', 'desc'],
        'spent' => ['cs.spent', 'desc'],
        'orders' => ['cs.orders_count', 'desc'],
        'first_order' => ['cs.first_order_at', 'asc'],
        'name' => ['users.name', 'asc'],
    ];

    public function updating($name): void
    {
        if (in_array($name, ['search', 'segment', 'tag', 'sort'], true)) {
            $this->resetPage();
        }
    }

    private function filtered(CustomerInsightsService $insights)
    {
        $sellerId = auth()->id();
        $q = $insights->query($sellerId);
        $q = $insights->applySegment($q, $this->segment, $sellerId);

        if (($term = trim($this->search)) !== '') {
            $q->where(fn ($w) => $w->where('users.name', 'like', "%{$term}%")->orWhere('users.email', 'like', "%{$term}%"));
        }

        if ($this->tag !== '') {
            $buyerIds = SellerCustomerNote::where('seller_id', $sellerId)->get()
                ->filter(fn ($n) => in_array($this->tag, $n->tags ?? [], true))
                ->pluck('buyer_id');
            $q->whereIn('users.id', $buyerIds);
        }

        [$col, $dir] = self::SORTS[$this->sort] ?? self::SORTS['last_order'];

        return $q->orderBy($col, $dir)->orderBy('users.id');
    }

    /** CSV of the current filtered list. Deliberately no email addresses — buyer contact stays on-platform. */
    public function export(CustomerInsightsService $insights)
    {
        $rows = $this->filtered($insights)->get();
        $sellerId = auth()->id();

        return response()->streamDownload(function () use ($rows, $insights, $sellerId) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Customer', 'Location', 'Orders', 'Units', 'Lifetime spend', 'Avg order', 'First order', 'Last order', 'Segments']);
            foreach ($rows as $c) {
                fputcsv($out, [
                    $c->name,
                    $insights->lastLocation($sellerId, $c->id),
                    $c->orders_count,
                    $c->units,
                    number_format((float) $c->spent, 2, '.', ''),
                    number_format((float) $c->spent / max(1, (int) $c->orders_count), 2, '.', ''),
                    Carbon::parse($c->first_order_at)->format('Y-m-d'),
                    Carbon::parse($c->last_order_at)->format('Y-m-d'),
                    implode(' / ', $insights->badges($c)),
                ]);
            }
            fclose($out);
        }, 'customers-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function render(CustomerInsightsService $insights)
    {
        $sellerId = auth()->id();
        $customers = $this->filtered($insights)->paginate(25);

        $notes = SellerCustomerNote::where('seller_id', $sellerId)
            ->whereIn('buyer_id', $customers->pluck('id'))->get()->keyBy('buyer_id');

        $allTags = SellerCustomerNote::where('seller_id', $sellerId)->pluck('tags')
            ->flatten()->filter()->unique()->sort()->values();

        return view('livewire.seller.customers', [
            'customers' => $customers,
            'summary' => $insights->summary($sellerId),
            // The blocked tab only matters once the seller has blocked someone.
            'segments' => \App\Models\SellerBlockedBuyer::where('seller_id', $sellerId)->exists() || $this->segment === 'blocked'
                ? CustomerInsightsService::SEGMENTS
                : array_diff_key(CustomerInsightsService::SEGMENTS, ['blocked' => true]),
            'notes' => $notes,
            'allTags' => $allTags,
            'insights' => $insights,
        ]);
    }
}
