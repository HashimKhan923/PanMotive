<?php

namespace App\Livewire\Storefront;

use App\Services\GarageService;
use Livewire\Attributes\On;
use Livewire\Component;

class GarageBanner extends Component
{
    public ?int $vehicleId = null;

    public ?string $label = null;

    public function mount(GarageService $garage): void
    {
        $this->hydrateFrom($garage);
    }

    #[On('garage-updated')]
    public function refresh(GarageService $garage): void
    {
        $this->hydrateFrom($garage);
    }

    public function clear(GarageService $garage): void
    {
        $garage->clear();
        $this->vehicleId = null;
        $this->label = null;
        $this->dispatch('garage-updated');
    }

    private function hydrateFrom(GarageService $garage): void
    {
        $this->vehicleId = $garage->vehicleId();
        $this->label = $garage->label();
    }

    public function render()
    {
        return view('livewire.storefront.garage-banner');
    }
}
