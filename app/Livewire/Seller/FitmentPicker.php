<?php

namespace App\Livewire\Seller;

use App\Models\Vehicle;
use Livewire\Attributes\On;
use Livewire\Component;

class FitmentPicker extends Component
{
    /** @var array<int, int> */
    public array $selected = [];

    public string $search = '';

    public function mount(array $selected = []): void
    {
        $this->selected = array_map('intval', $selected);
    }

    #[On('fitments-suggested')]
    public function addSuggested(array $ids): void
    {
        $this->selected = array_values(array_unique(array_merge(
            $this->selected,
            array_map('intval', $ids)
        )));

        $this->dispatch('fitment-updated', ids: $this->selected);
    }

    public function toggle(int $vehicleId): void
    {
        if (in_array($vehicleId, $this->selected, true)) {
            $this->selected = array_values(array_diff($this->selected, [$vehicleId]));
        } else {
            $this->selected[] = $vehicleId;
        }

        $this->dispatch('fitment-updated', ids: $this->selected);
    }

    public function render()
    {
        $results = $this->search === ''
            ? collect()
            : Vehicle::query()
                ->with(['make', 'model'])
                ->where(function ($q) {
                    $q->whereHas('make', fn ($m) => $m->where('name', 'like', "%{$this->search}%"))
                        ->orWhereHas('model', fn ($m) => $m->where('name', 'like', "%{$this->search}%"));
                })
                ->limit(20)
                ->get();

        $chosen = Vehicle::with(['make', 'model'])->whereIn('id', $this->selected)->get();

        return view('livewire.seller.fitment-picker', compact('results', 'chosen'));
    }
}
