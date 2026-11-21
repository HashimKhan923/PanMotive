<?php

namespace App\Http\Controllers\Api\Shop;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\GarageService;
use App\Services\Vehicles\VinDecoder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The vehicle picker (make → model → year → engine), VIN scanning, and the garage. The app keeps the
 * garage on the phone and sends the chosen vehicle with each request; signed-in shoppers also have it
 * saved on their account, like on the website.
 */
class VehicleController extends Controller
{
    public function makes(): JsonResponse
    {
        $makes = Cache::remember('app.vehicle.makes', 3600, fn () => VehicleMake::orderBy('name')->get(['id', 'name', 'slug'])->toArray());

        return response()->json(['makes' => $makes]);
    }

    public function models(VehicleMake $make): JsonResponse
    {
        return response()->json([
            'models' => VehicleModel::where('vehicle_make_id', $make->id)->orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }

    public function years(VehicleModel $model): JsonResponse
    {
        return response()->json([
            'years' => Vehicle::where('vehicle_model_id', $model->id)->distinct()->orderByDesc('year')->pluck('year')->values(),
        ]);
    }

    public function engines(VehicleModel $model, int $year): JsonResponse
    {
        $ids = Vehicle::where('vehicle_model_id', $model->id)->where('year', $year)
            ->whereNotNull('vehicle_engine_id')->distinct()->pluck('vehicle_engine_id');

        return response()->json([
            'engines' => $ids->isNotEmpty() ? VehicleEngine::whereIn('id', $ids)->orderBy('name')->get(['id', 'name']) : [],
        ]);
    }

    /** The exact vehicle for a picked model + year (+ engine), like the website's "Find parts". */
    public function resolve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'model_id' => 'required|integer', 'year' => 'required|integer|min:1900|max:2100', 'engine_id' => 'nullable|integer',
        ]);

        $vehicle = Vehicle::where('vehicle_model_id', $data['model_id'])->where('year', $data['year'])
            ->when($data['engine_id'] ?? null, fn ($q, $e) => $q->where('vehicle_engine_id', $e))->first()
            ?? Vehicle::where('vehicle_model_id', $data['model_id'])->where('year', $data['year'])->first();

        abort_unless($vehicle, 404, 'We don\'t have that vehicle yet.');

        return response()->json(['vehicle' => self::vehicle($vehicle)]);
    }

    /** A VIN typed or scanned with the camera. */
    public function vin(Request $request, VinDecoder $decoder): JsonResponse
    {
        $request->validate(['vin' => 'required|string|min:11|max:20']);
        $result = $decoder->decode((string) $request->vin);

        if (! $result) {
            return response()->json(['message' => 'We couldn\'t read that VIN. Check all 17 characters and try again.', 'code' => 'vin_unreadable'], 422);
        }

        return response()->json([
            'decoded' => $result['decoded'],
            'vehicle' => $result['vehicle'] ? self::vehicle($result['vehicle']) : null,
            // Partly matched (e.g. the model isn't in our list): the app pre-fills the picker with these.
            'match' => $result['match'],
        ]);
    }

    /** Save the garage vehicle on the account (signed-in shoppers), so it follows them to the website. */
    public function setGarage(Request $request, GarageService $garage): JsonResponse
    {
        $request->validate(['vehicle_id' => 'nullable|integer']);
        if (! $request->filled('vehicle_id')) {
            $garage->clear();

            return response()->json(['garage' => null]);
        }

        $vehicle = Vehicle::with('make', 'model', 'engine')->findOrFail((int) $request->vehicle_id);
        $garage->set($vehicle);

        return response()->json(['garage' => self::vehicle($vehicle)]);
    }

    /** @return array<string, mixed> */
    public static function vehicle(Vehicle $v): array
    {
        $v->loadMissing('make', 'model', 'engine');

        return [
            'id' => $v->id,
            'label' => $v->label(),
            'year' => $v->year,
            'make' => $v->make ? ['id' => $v->make->id, 'name' => $v->make->name, 'slug' => $v->make->slug] : null,
            'model' => $v->model ? ['id' => $v->model->id, 'name' => $v->model->name, 'slug' => $v->model->slug] : null,
            'engine' => $v->engine ? ['id' => $v->engine->id, 'name' => $v->engine->name] : null,
            'trim' => $v->trim,
        ];
    }
}
