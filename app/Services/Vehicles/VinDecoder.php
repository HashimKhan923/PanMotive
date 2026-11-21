<?php

namespace App\Services\Vehicles;

use App\Models\Vehicle;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Reads a VIN (typed, or scanned from the windscreen / door sticker in the app) and finds the
 * vehicle in our catalog. Uses the US government's free NHTSA vPIC decoder — the same source our
 * vehicle list was built from, so names match.
 */
class VinDecoder
{
    private const VALID = '/^[A-HJ-NPR-Z0-9]{17}$/';

    /**
     * @return array{decoded: array{year: ?int, make: ?string, model: ?string, engine: ?string, trim: ?string},
     *               vehicle: ?Vehicle, match: array{make_id: ?int, model_id: ?int, year: ?int}}|null
     */
    public function decode(string $vin): ?array
    {
        $vin = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vin));
        if (! preg_match(self::VALID, $vin)) {
            return null; // 17 characters, never I, O or Q
        }

        $raw = Cache::remember("vin.{$vin}", now()->addDays(30), function () use ($vin) {
            try {
                return Http::timeout(12)->retry(2, 300)
                    ->get("https://vpic.nhtsa.dot.gov/api/vehicles/DecodeVinValues/{$vin}", ['format' => 'json'])
                    ->throw()->json('Results.0') ?: [];
            } catch (\Throwable $e) {
                report($e);

                return null; // not cached (null isn't stored), so it's tried again next time
            }
        });
        if (! $raw || blank($raw['Make'] ?? null) || blank($raw['ModelYear'] ?? null)) {
            return null;
        }

        $year = (int) $raw['ModelYear'];
        $makeName = Str::title(strtolower(trim((string) $raw['Make'])));
        $modelName = trim((string) ($raw['Model'] ?? ''));
        $liters = is_numeric($raw['DisplacementL'] ?? null) ? number_format((float) $raw['DisplacementL'], 1) : null;
        $cyl = trim((string) ($raw['EngineCylinders'] ?? ''));
        $engine = trim(($liters ? "{$liters}L" : '').($cyl !== '' ? " {$cyl}-cyl" : ''));

        $make = VehicleMake::whereRaw('LOWER(name) = ?', [strtolower($makeName)])->first();
        $model = $make && $modelName !== ''
            ? (VehicleModel::where('vehicle_make_id', $make->id)->whereRaw('LOWER(name) = ?', [strtolower($modelName)])->first()
                ?? VehicleModel::where('vehicle_make_id', $make->id)->where('name', 'like', $modelName.'%')->orderByRaw('LENGTH(name)')->first())
            : null;

        $vehicle = null;
        if ($model) {
            $candidates = Vehicle::with('engine', 'make', 'model')->where('vehicle_model_id', $model->id)->where('year', $year)->get();
            // Prefer the engine with the same size, e.g. "2.0L".
            $vehicle = ($liters ? $candidates->first(fn ($v) => $v->engine && str_contains($v->engine->name, $liters.'L')) : null)
                ?? $candidates->first();
        }

        return [
            'decoded' => [
                'year' => $year ?: null,
                'make' => $make?->name ?? $makeName,
                'model' => $model?->name ?? ($modelName ?: null),
                'engine' => $engine ?: null,
                'trim' => trim((string) ($raw['Trim'] ?? '')) ?: null,
            ],
            'vehicle' => $vehicle,
            'match' => ['make_id' => $make?->id, 'model_id' => $model?->id, 'year' => $year ?: null],
        ];
    }
}
