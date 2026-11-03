<?php

namespace App\Livewire\Storefront;

use App\Models\Vehicle;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\GarageService;
use Livewire\Component;

class VehicleSelector extends Component
{
    public ?int $makeId = null;

    public ?int $modelId = null;

    public ?int $year = null;

    public ?int $engineId = null;

    public function mount(GarageService $garage): void
    {
        if ($id = $garage->vehicleId()) {
            $vehicle = Vehicle::find($id);
            if ($vehicle) {
                $this->makeId = $vehicle->vehicle_make_id;
                $this->modelId = $vehicle->vehicle_model_id;
                $this->year = $vehicle->year;
                $this->engineId = $vehicle->vehicle_engine_id;
            }
        }
    }

    public function updatedMakeId(): void
    {
        $this->modelId = null;
        $this->year = null;
        $this->engineId = null;
    }

    public function updatedModelId(): void
    {
        $this->year = null;
        $this->engineId = null;
    }

    public function updatedYear(): void
    {
        $this->engineId = null;
    }

    public function findParts(GarageService $garage)
    {
        $vehicle = $this->resolveVehicle();

        if ($vehicle) {
            $garage->set($vehicle);

            return redirect()->route('products.index', ['vehicleId' => $vehicle->id]);
        }

        // Only part of the vehicle picked: narrow to what we know instead of showing everything.
        $make = $this->makeId ? VehicleMake::find($this->makeId) : null;

        return redirect()->route('products.index', array_filter([
            'make' => $make?->slug,
            'model' => $this->modelId ? VehicleModel::whereKey($this->modelId)->value('slug') : null,
        ]));
    }

    private function resolveVehicle(): ?Vehicle
    {
        if (! $this->modelId || ! $this->year) {
            return null;
        }

        return Vehicle::where('vehicle_model_id', $this->modelId)
            ->where('year', $this->year)
            ->when($this->engineId, fn ($q) => $q->where('vehicle_engine_id', $this->engineId))
            ->first()
            ?? Vehicle::where('vehicle_model_id', $this->modelId)->where('year', $this->year)->first();
    }

    public function render()
    {
        $engineIds = ($this->modelId && $this->year)
            ? Vehicle::where('vehicle_model_id', $this->modelId)->where('year', $this->year)
                ->whereNotNull('vehicle_engine_id')->distinct()->pluck('vehicle_engine_id')
            : collect();

        return view('livewire.storefront.vehicle-selector', [
            'makes' => VehicleMake::orderBy('name')->get(['id', 'name']),
            'models' => $this->makeId
                ? VehicleModel::where('vehicle_make_id', $this->makeId)->orderBy('name')->get(['id', 'name'])
                : collect(),
            'years' => $this->modelId
                ? Vehicle::where('vehicle_model_id', $this->modelId)->distinct()->orderByDesc('year')->pluck('year')
                : collect(),
            'engines' => $engineIds->isNotEmpty()
                ? VehicleEngine::whereIn('id', $engineIds)->orderBy('name')->get(['id', 'name'])
                : collect(),
        ]);
    }
}
