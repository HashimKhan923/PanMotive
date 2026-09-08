<?php

namespace App\Livewire\Storefront;

use App\Models\Vehicle;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Livewire\Component;

class VehicleSelector extends Component
{
    public ?int $makeId = null;

    public ?int $modelId = null;

    public ?int $year = null;

    public function updatedMakeId(): void
    {
        $this->modelId = null;
        $this->year = null;
    }

    public function updatedModelId(): void
    {
        $this->year = null;
    }

    public function findParts()
    {
        $query = ['vehicleId' => null];

        if ($this->modelId && $this->year) {
            $vehicle = Vehicle::where('vehicle_model_id', $this->modelId)
                ->where('year', $this->year)
                ->first();

            if ($vehicle) {
                return redirect()->route('products.index', ['vehicleId' => $vehicle->id]);
            }
        }

        return redirect()->route('products.index');
    }

    public function render()
    {
        return view('livewire.storefront.vehicle-selector', [
            'makes' => VehicleMake::orderBy('name')->get(['id', 'name']),
            'models' => $this->makeId
                ? VehicleModel::where('vehicle_make_id', $this->makeId)->orderBy('name')->get(['id', 'name'])
                : collect(),
            'years' => $this->modelId
                ? Vehicle::where('vehicle_model_id', $this->modelId)->distinct()->orderByDesc('year')->pluck('year')
                : collect(),
        ]);
    }
}
