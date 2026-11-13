<?php

namespace App\Livewire\Seller;

use App\Models\CampaignClick;
use App\Models\MarketingCampaign;
use App\Models\Product;
use App\Services\Marketing\CampaignSender;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** How a sent campaign did: delivered, opens, clicks (bots left out), orders, revenue, click map. */
#[Layout('layouts.seller')]
class CampaignReport extends Component
{
    use WithPagination;

    public int $campaignId;

    #[Url]
    public string $who = 'all';

    public function mount(MarketingCampaign $campaign): void
    {
        abort_unless($campaign->seller_id === auth()->id(), 404);
        $this->campaignId = $campaign->id;
        app(CampaignSender::class)->refreshStats($campaign);
    }

    public function updatedWho(): void
    {
        $this->resetPage();
    }

    /** @return array<string, int> link key => human clicks */
    public static function clickCounts(int $campaignId): array
    {
        return CampaignClick::where('marketing_campaign_id', $campaignId)->where('is_bot', false)
            ->selectRaw('link_key, COUNT(*) as n')->groupBy('link_key')->pluck('n', 'link_key')->map(fn ($n) => (int) $n)->all();
    }

    public function render()
    {
        $c = MarketingCampaign::where('seller_id', auth()->id())->findOrFail($this->campaignId);
        $s = $c->stats ?? [];
        $sent = (int) ($s['recipients'] ?? 0);
        $delivered = max(0, $sent - (int) ($s['bounced'] ?? 0));
        $pct = fn ($a, $b) => $b > 0 ? round($a / $b * 100, 1) : 0.0;

        // Opens and human clicks per hour for the first 48 hours.
        $start = $c->sent_at ?? $c->created_at;
        $hours = collect(range(0, 47))->map(fn ($h) => ['opens' => 0, 'clicks' => 0]);
        foreach ($c->recipients()->whereNotNull('opened_at')->pluck('opened_at') as $t) {
            $h = (int) floor($start->diffInMinutes($t, false) / 60);
            if ($h >= 0 && $h < 48) {
                $hours[$h] = ['opens' => $hours[$h]['opens'] + 1, 'clicks' => $hours[$h]['clicks']];
            }
        }
        foreach (CampaignClick::where('marketing_campaign_id', $c->id)->where('is_bot', false)->pluck('clicked_at') as $t) {
            $h = (int) floor($start->diffInMinutes($t, false) / 60);
            if ($h >= 0 && $h < 48) {
                $hours[$h] = ['opens' => $hours[$h]['opens'], 'clicks' => $hours[$h]['clicks'] + 1];
            }
        }

        $links = CampaignClick::where('marketing_campaign_id', $c->id)->where('is_bot', false)
            ->selectRaw('link_key, url, COUNT(*) as n')->groupBy('link_key', 'url')->orderByDesc('n')->limit(15)->get();
        $productIds = $links->map(fn ($l) => preg_match('/:p(\d+)$/', $l->link_key, $m) ? (int) $m[1] : null)->filter()->unique();
        $products = Product::whereIn('id', $productIds)->pluck('title', 'id');
        $topProducts = $links->filter(fn ($l) => preg_match('/:p(\d+)$/', $l->link_key))
            ->groupBy(fn ($l) => (int) preg_replace('/^.*:p/', '', $l->link_key))
            ->map(fn ($g, $pid) => ['title' => $products[$pid] ?? 'Removed listing', 'clicks' => $g->sum('n')])->sortByDesc('clicks')->take(5);

        $people = $c->recipients()->with('user:id,name,email')
            ->withCount(['clicksLog as human_clicks' => fn ($q) => $q->where('is_bot', false)])
            ->when($this->who === 'opened', fn ($q) => $q->whereNotNull('opened_at'))
            ->when($this->who === 'clicked', fn ($q) => $q->whereNotNull('clicked_at'))
            ->when($this->who === 'ordered', fn ($q) => $q->whereNotNull('converted_order_id'))
            ->when($this->who === 'not_opened', fn ($q) => $q->whereNotNull('sent_at')->whereNull('opened_at'))
            ->when($this->who === 'problems', fn ($q) => $q->where(fn ($w) => $w->whereNotNull('bounced_at')->orWhereNotNull('failure')))
            ->orderByDesc('conversion_value')->orderByDesc('clicked_at')->paginate(25);

        return view('livewire.seller.campaign-report', [
            'campaign' => $c,
            'sent' => $sent,
            'delivered' => $delivered,
            'opened' => (int) ($s['opened'] ?? 0),
            'clicked' => (int) ($s['clicked'] ?? 0),
            'clicks' => (int) ($s['clicks'] ?? 0),
            'orders' => (int) ($s['converted'] ?? 0),
            'revenue' => (float) ($s['revenue'] ?? 0),
            'unsubscribed' => (int) ($s['unsubscribed'] ?? 0),
            'complaints' => (int) ($s['complaints'] ?? 0),
            'bounced' => (int) ($s['bounced'] ?? 0),
            'failed' => (int) ($s['failed'] ?? 0),
            'waiting' => (int) ($s['waiting'] ?? 0),
            'openRate' => $pct((int) ($s['opened'] ?? 0), $delivered),
            'clickRate' => $pct((int) ($s['clicked'] ?? 0), $delivered),
            'clickToOpen' => $pct((int) ($s['clicked'] ?? 0), (int) ($s['opened'] ?? 0)),
            'orderRate' => $pct((int) ($s['converted'] ?? 0), $delivered),
            'hours' => $hours,
            'peak' => max(1, $hours->max(fn ($h) => max($h['opens'], $h['clicks']))),
            'links' => $links,
            'productTitles' => $products,
            'topProducts' => $topProducts,
            'people' => $people,
        ]);
    }
}
