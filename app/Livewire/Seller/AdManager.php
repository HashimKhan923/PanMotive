<?php

namespace App\Livewire\Seller;

use App\Models\AdCampaign;
use App\Models\Category;
use App\Models\Product;
use App\Models\VehicleMake;
use App\Services\Marketing\MarketingCopyService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.seller')]
class AdManager extends Component
{
    public bool $showForm = false;
    public ?int $editingId = null;

    public string $name = '';
    public string $objective = 'sales';
    public ?float $dailyBudget = 10;
    public array $placements = ['search'];
    public array $categories = [];
    public array $vehicleMakeIds = [];
    public string $keywords = '';
    public ?string $endsAt = null;

    /** @var array<int, array{id:?int, product_id:?int, headline:string, primary_text:string}> */
    public array $creatives = [];

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'objective' => 'required|in:traffic,sales,awareness',
            'dailyBudget' => 'required|numeric|min:1|max:1000',
            'placements' => 'required|array|min:1',
            'creatives' => 'required|array|min:1',
            'creatives.*.product_id' => 'required|integer',
            'creatives.*.headline' => 'nullable|string|max:40',
            'creatives.*.primary_text' => 'nullable|string|max:300',
            'endsAt' => 'nullable|date|after:now',
        ];
    }

    public function newCampaign(): void
    {
        $this->reset('editingId', 'name', 'keywords', 'endsAt', 'categories', 'vehicleMakeIds');
        $this->objective = 'sales';
        $this->dailyBudget = 10;
        $this->placements = ['search'];
        $this->creatives = [['id' => null, 'product_id' => null, 'headline' => '', 'primary_text' => '']];
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $c = $this->owned($id);
        $this->editingId = $c->id;
        $this->name = $c->name;
        $this->objective = $c->objective;
        $this->dailyBudget = (float) $c->daily_budget;
        $this->placements = $c->targets('placements');
        $this->categories = $c->targets('categories');
        $this->vehicleMakeIds = $c->targets('vehicle_make_ids');
        $this->keywords = implode(', ', $c->targets('keywords'));
        $this->endsAt = $c->ends_at?->format('Y-m-d\TH:i');
        $this->creatives = $c->creatives->map(fn ($cr) => [
            'id' => $cr->id, 'product_id' => $cr->product_id,
            'headline' => $cr->headline ?? '', 'primary_text' => $cr->primary_text ?? '',
        ])->all();
        $this->showForm = true;
    }

    public function addCreative(): void
    {
        $this->creatives[] = ['id' => null, 'product_id' => null, 'headline' => '', 'primary_text' => ''];
    }

    public function removeCreative(int $i): void
    {
        unset($this->creatives[$i]);
        $this->creatives = array_values($this->creatives);
    }

    public function generateCreative(int $i, MarketingCopyService $copy): void
    {
        $pid = $this->creatives[$i]['product_id'] ?? null;
        $product = $pid ? Product::where('seller_id', auth()->id())->with('brand')->find($pid) : null;
        if (! $product) {
            return;
        }
        $out = $copy->adCopy($product, $this->objective);
        $this->creatives[$i]['headline'] = $out['headline'];
        $this->creatives[$i]['primary_text'] = $out['primary_text'];
    }

    public function save(): void
    {
        $data = $this->validate();
        $owned = Product::where('seller_id', auth()->id())->pluck('id');
        foreach ($this->creatives as $c) {
            abort_unless($owned->contains($c['product_id']), 403);
        }

        $campaign = $this->editingId ? $this->owned($this->editingId) : new AdCampaign(['seller_id' => auth()->id()]);
        $campaign->fill([
            'name' => $data['name'],
            'objective' => $data['objective'],
            'status' => 'active',
            'daily_budget' => $data['dailyBudget'],
            'targeting' => [
                'placements' => array_values($this->placements),
                'categories' => array_map('intval', $this->categories),
                'vehicle_make_ids' => array_map('intval', $this->vehicleMakeIds),
                'keywords' => collect(explode(',', $this->keywords))->map(fn ($k) => trim($k))->filter()->values()->all(),
            ],
            'starts_at' => $campaign->starts_at ?? now(),
            'ends_at' => $this->endsAt ?: null,
        ])->save();

        $keep = [];
        foreach ($this->creatives as $c) {
            $row = $campaign->creatives()->updateOrCreate(
                ['id' => $c['id']],
                ['product_id' => $c['product_id'], 'headline' => $c['headline'] ?: null, 'primary_text' => $c['primary_text'] ?: null, 'is_active' => true],
            );
            $keep[] = $row->id;
        }
        $campaign->creatives()->whereNotIn('id', $keep)->delete();

        $this->showForm = false;
        $this->dispatch('toast', message: 'Ad campaign live.');
        $this->dispatch('celebrate');
    }

    public function toggle(int $id): void
    {
        $c = $this->owned($id);
        // A manual pause/resume is the seller's decision — it overrides the auto out-of-credit pause.
        $c->update(['status' => $c->status === 'active' ? 'paused' : 'active', 'paused_for_credit' => false]);
    }

    public function delete(int $id): void
    {
        $this->owned($id)->delete();
    }

    private function owned(int $id): AdCampaign
    {
        return AdCampaign::where('seller_id', auth()->id())->with('creatives')->findOrFail($id);
    }

    public function render()
    {
        return view('livewire.seller.ad-manager', [
            'campaigns' => AdCampaign::where('seller_id', auth()->id())->with('creatives.product:id,title')->latest()->get(),
            'products' => Product::where('seller_id', auth()->id())->where('status', 'active')->orderBy('title')->get(['id', 'title']),
            'allCategories' => Category::orderBy('name')->get(['id', 'name']),
            'makes' => VehicleMake::orderBy('name')->get(['id', 'name']),
            'adCredit' => auth()->user()->sellerProfile->ad_credit,
            'cpc' => AdCampaign::CPC,
        ]);
    }
}
