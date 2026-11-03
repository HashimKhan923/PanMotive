<?php

namespace App\Livewire\Seller;

use App\Models\ShippingPolicy;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.seller')]
class ShippingPolicies extends Component
{
    /** Valid US state / DC / territory codes. */
    public const US_STATES = [
        'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'DC', 'FL', 'GA', 'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA',
        'ME', 'MD', 'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ', 'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR',
        'PA', 'RI', 'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY', 'PR', 'GU', 'VI', 'AS', 'MP',
    ];

    public ?int $editingId = null;

    public string $name = '';
    public int $handlingDays = 2;
    public string $method = 'weight'; // free | flat | weight
    public ?float $flatRate = null;
    public ?float $additionalRate = null;
    public ?float $freeOver = null;
    public bool $isDefault = false;
    /** @var array<int,array{state:string,amount:float}> */
    public array $surcharges = [];
    /** @var list<string> */
    public array $excludedStates = [];
    public string $stateToAdd = '';

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:80',
            'handlingDays' => 'required|integer|min:0|max:30',
            'method' => 'required|in:free,flat,weight',
            'flatRate' => $this->method === 'flat' ? 'required|numeric|min:0.01|max:1000' : 'nullable',
            'additionalRate' => $this->method === 'flat' ? 'nullable|numeric|min:0|max:1000' : 'nullable',
            'freeOver' => $this->method === 'free' ? 'nullable' : 'nullable|numeric|min:0.01|max:100000',
            'surcharges.*.state' => 'required|string|size:2|in:'.implode(',', self::US_STATES),
            'surcharges.*.amount' => 'required|numeric|min:0|max:1000',
            'excludedStates.*' => 'in:'.implode(',', self::US_STATES),
        ];
    }

    protected function messages(): array
    {
        return [
            'flatRate.required' => 'Enter the flat rate, or choose Free shipping.',
            'flatRate.min' => 'For $0 shipping choose "Free shipping" instead.',
            'surcharges.*.state.in' => 'Use a 2-letter US state code, e.g. AK.',
        ];
    }

    public function newPolicy(): void
    {
        $this->reset('editingId', 'name', 'flatRate', 'additionalRate', 'freeOver', 'isDefault', 'surcharges', 'excludedStates', 'stateToAdd');
        $this->handlingDays = 2;
        $this->method = 'weight';
        $this->resetErrorBag();
    }

    public function edit(int $id): void
    {
        $p = $this->owned($id);
        $this->editingId = $p->id;
        $this->name = $p->name;
        $this->handlingDays = $p->handling_days;
        $this->method = $p->method();
        $this->flatRate = $p->method() === 'flat' ? (float) $p->flat_rate : null;
        $this->additionalRate = $p->additional_item_rate !== null ? (float) $p->additional_item_rate : null;
        $this->freeOver = $p->free_over !== null ? (float) $p->free_over : null;
        $this->isDefault = $p->is_default;
        $this->surcharges = collect($p->region_surcharges ?? [])
            ->map(fn ($amt, $state) => ['state' => $state, 'amount' => (float) $amt])->values()->all();
        $this->excludedStates = $p->excluded_states ?? [];
        $this->resetErrorBag();
    }

    public function addSurcharge(): void
    {
        $this->surcharges[] = ['state' => '', 'amount' => 0];
    }

    public function removeSurcharge(int $i): void
    {
        unset($this->surcharges[$i]);
        $this->surcharges = array_values($this->surcharges);
    }

    public function addExcluded(): void
    {
        $code = strtoupper(trim($this->stateToAdd));
        if (! in_array($code, self::US_STATES, true)) {
            $this->addError('stateToAdd', 'Use a 2-letter US state code, e.g. CA.');

            return;
        }
        $this->excludedStates = array_values(array_unique([...$this->excludedStates, $code]));
        $this->stateToAdd = '';
    }

    public function addPreset(string $preset): void
    {
        $codes = ShippingPolicy::STATE_PRESETS[$preset] ?? [];
        $this->excludedStates = array_values(array_unique([...$this->excludedStates, ...$codes]));
    }

    public function removeExcluded(string $code): void
    {
        $this->excludedStates = array_values(array_diff($this->excludedStates, [$code]));
    }

    public function save(): void
    {
        foreach ($this->surcharges as $i => $s) {
            $this->surcharges[$i]['state'] = strtoupper(trim((string) $s['state']));
        }
        $this->validate();

        $regions = collect($this->surcharges)
            ->filter(fn ($s) => filled($s['state']))
            ->mapWithKeys(fn ($s) => [strtoupper($s['state']) => round((float) $s['amount'], 2)])
            ->all();

        $payload = [
            'seller_id' => auth()->id(),
            'name' => $this->name,
            'handling_days' => $this->handlingDays,
            // free = $0 flat, flat = the rate, weight = no flat rate (calculated by weight).
            'flat_rate' => match ($this->method) { 'free' => 0, 'flat' => $this->flatRate, default => null },
            'additional_item_rate' => $this->method === 'flat' ? $this->additionalRate : null,
            'free_over' => $this->method === 'free' ? null : $this->freeOver,
            'is_default' => $this->isDefault,
            'region_surcharges' => $regions ?: null,
            'excluded_states' => $this->excludedStates ?: null,
            'no_ship_alaska_hawaii' => in_array('AK', $this->excludedStates, true) && in_array('HI', $this->excludedStates, true),
        ];

        $policy = $this->editingId
            ? tap($this->owned($this->editingId))->update($payload)
            : ShippingPolicy::create($payload);

        // A seller's first policy is their default automatically.
        if ($this->isDefault || ! ShippingPolicy::where('seller_id', auth()->id())->where('is_default', true)->exists()) {
            // Both as direct queries: the model already holds is_default=true in memory, so a
            // model update after the bulk reset would see "no change" and silently not save.
            ShippingPolicy::where('seller_id', auth()->id())->whereKeyNot($policy->id)->update(['is_default' => false]);
            ShippingPolicy::whereKey($policy->id)->update(['is_default' => true]);
        }

        $this->newPolicy();
        $this->dispatch('toast', message: 'Shipping policy saved.');
    }

    public function makeDefault(int $id): void
    {
        $p = $this->owned($id);
        ShippingPolicy::where('seller_id', auth()->id())->update(['is_default' => false]);
        $p->update(['is_default' => true]);
        $this->dispatch('toast', message: "\"{$p->name}\" is now your default.");
    }

    public function delete(int $id): void
    {
        $p = $this->owned($id);
        $wasDefault = $p->is_default;
        $p->products()->update(['shipping_policy_id' => null]);
        $p->delete();

        // Keep a default in place if one is left.
        if ($wasDefault && ($next = ShippingPolicy::where('seller_id', auth()->id())->orderBy('id')->first())) {
            $next->update(['is_default' => true]);
        }

        $this->dispatch('toast', message: 'Policy deleted. Listings using it now follow your default.');
    }

    private function owned(int $id): ShippingPolicy
    {
        return ShippingPolicy::where('seller_id', auth()->id())->findOrFail($id);
    }

    public function render()
    {
        return view('livewire.seller.shipping-policies', [
            'policies' => ShippingPolicy::where('seller_id', auth()->id())
                ->withCount('products')->orderByDesc('is_default')->orderBy('name')->get(),
            'presets' => ShippingPolicy::STATE_PRESETS,
        ]);
    }
}
