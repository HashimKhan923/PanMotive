<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AdminTools;
use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The make → model → year list that the vehicle selector and part fitment are built on. */
class VehicleController extends Controller
{
    use AdminTools;

    public function index(Request $request)
    {
        $makes = VehicleMake::withCount('models')->orderBy('name')->get();
        $make = $request->filled('make') ? $makes->firstWhere('id', $request->integer('make')) : $makes->first();
        $models = $make ? VehicleModel::where('vehicle_make_id', $make->id)->orderBy('name')->get() : collect();
        $model = $request->filled('model') ? $models->firstWhere('id', $request->integer('model')) : $models->first();

        $vehicles = $model
            ? Vehicle::where('vehicle_model_id', $model->id)->with('engine')->orderByDesc('year')->get()
            : collect();
        $fitted = DB::table('product_fitments')->whereIn('vehicle_id', $vehicles->pluck('id'))
            ->selectRaw('vehicle_id, COUNT(*) AS c')->groupBy('vehicle_id')->pluck('c', 'vehicle_id');

        return view('admin.vehicles.index', compact('makes', 'make', 'models', 'model', 'vehicles', 'fitted'));
    }

    public function storeMake(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:80|unique:vehicle_makes,name']);
        $make = VehicleMake::create(['name' => $data['name'], 'slug' => $this->slug($data['name'], fn ($s) => VehicleMake::where('slug', $s)->exists())]);
        $this->audit('Added vehicle make', $make);

        return redirect()->route('admin.vehicles.index', ['make' => $make->id])->with('success', "{$make->name} added.");
    }

    public function storeModel(Request $request)
    {
        $data = $request->validate(['vehicle_make_id' => 'required|exists:vehicle_makes,id', 'name' => 'required|string|max:80']);
        $makeId = (int) $data['vehicle_make_id'];

        if (VehicleModel::where('vehicle_make_id', $makeId)->where('name', $data['name'])->exists()) {
            return back()->with('error', 'That model already exists for this make.');
        }

        $model = VehicleModel::create([
            'vehicle_make_id' => $makeId, 'name' => $data['name'],
            'slug' => $this->slug($data['name'], fn ($s) => VehicleModel::where('vehicle_make_id', $makeId)->where('slug', $s)->exists()),
        ]);
        $this->audit('Added vehicle model', $model);

        return redirect()->route('admin.vehicles.index', ['make' => $makeId, 'model' => $model->id])->with('success', "{$model->name} added.");
    }

    /** Add a run of years at once: most models are sold across many years. */
    public function storeYears(Request $request)
    {
        $maxYear = (int) now()->year + 2;
        $data = $request->validate([
            'vehicle_model_id' => 'required|exists:vehicle_models,id',
            'from' => "required|integer|min:1900|max:{$maxYear}",
            'to' => "required|integer|gte:from|max:{$maxYear}",
        ]);

        $model = VehicleModel::findOrFail($data['vehicle_model_id']);
        $added = 0;
        foreach (range((int) $data['from'], (int) $data['to']) as $year) {
            $exists = Vehicle::where('vehicle_model_id', $model->id)->where('year', $year)->exists();
            if (! $exists) {
                Vehicle::create(['vehicle_make_id' => $model->vehicle_make_id, 'vehicle_model_id' => $model->id, 'year' => $year]);
                $added++;
            }
        }
        $this->audit('Added vehicle years', $model, ['from' => $data['from'], 'to' => $data['to'], 'added' => $added]);

        return back()->with('success', $added ? "{$added} years added." : 'Those years were already there.');
    }

    public function destroyVehicle(Vehicle $vehicle)
    {
        if (DB::table('product_fitments')->where('vehicle_id', $vehicle->id)->exists()) {
            return back()->with('error', 'Listings are fitted to this vehicle, so it can\'t be deleted.');
        }

        $this->audit('Deleted vehicle', null, ['vehicle' => $vehicle->load('make', 'model')->label()]);
        $vehicle->delete();

        return back()->with('success', 'Year removed.');
    }

    public function destroyModel(VehicleModel $vehicleModel)
    {
        $used = DB::table('product_fitments')->whereIn('vehicle_id', Vehicle::where('vehicle_model_id', $vehicleModel->id)->select('id'))->exists();
        if ($used) {
            return back()->with('error', 'Listings are fitted to this model, so it can\'t be deleted.');
        }

        $this->audit('Deleted vehicle model', null, ['name' => $vehicleModel->name]);
        $make = $vehicleModel->vehicle_make_id;
        Vehicle::where('vehicle_model_id', $vehicleModel->id)->delete();
        $vehicleModel->delete();

        return redirect()->route('admin.vehicles.index', ['make' => $make])->with('success', 'Model deleted.');
    }

    public function destroyMake(VehicleMake $vehicleMake)
    {
        $used = DB::table('product_fitments')->whereIn('vehicle_id', Vehicle::where('vehicle_make_id', $vehicleMake->id)->select('id'))->exists();
        if ($used) {
            return back()->with('error', 'Listings are fitted to this make, so it can\'t be deleted.');
        }

        $this->audit('Deleted vehicle make', null, ['name' => $vehicleMake->name]);
        Vehicle::where('vehicle_make_id', $vehicleMake->id)->delete();
        VehicleModel::where('vehicle_make_id', $vehicleMake->id)->delete();
        $vehicleMake->delete();

        return redirect()->route('admin.vehicles.index')->with('success', 'Make deleted.');
    }

    private function slug(string $name, callable $taken): string
    {
        $base = Str::slug($name) ?: 'vehicle';
        $slug = $base;
        for ($i = 2; $taken($slug); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
