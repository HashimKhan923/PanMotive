<?php

namespace Database\Seeders;

use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cars, SUVs, pickups and vans of the 56 well-known brands US drivers own, 1981 to 2027: one vehicle per make, model
 * and year (database/seeders/data/vehicles.json).
 *
 * Where the data comes from:
 *  - NHTSA vPIC (the US government's VIN database): every make and model by model year.
 *  - EPA fueleconomy.gov: the years each model was really on sale, used to correct NHTSA's wide
 *    VIN ranges on older cars and to fill years NHTSA skipped. Scion comes from EPA (NHTSA files
 *    Scion VINs under Toyota). Chevrolet Silverado / GMC Sierra are split into 1500, 2500 HD and
 *    3500 HD, which NHTSA lumps together.
 * Engines and trims are left blank rather than guessed.
 *
 * Safe to run again: makes, models and vehicles are matched by slug / model + year and only
 * missing ones are added. Sample models from the old hand-made list that aren't in the real data
 * (e.g. "F-250 Super Duty", now "F-250") are removed, but only when no product or order uses them.
 */
class VehicleMakeSeeder extends Seeder
{
    /** The old hand-made sample list (make => models) that this real catalog replaces. */
    private const LEGACY = [
        'Acura' => ['MDX', 'RDX', 'TLX'], 'Audi' => ['A4', 'A6', 'Q5', 'Q7'], 'BMW' => ['3 Series', '5 Series', 'X3', 'X5'],
        'Buick' => ['Enclave', 'Encore'], 'Cadillac' => ['Escalade', 'XT5'],
        'Chevrolet' => ['Silverado 1500', 'Silverado 2500 HD', 'Equinox', 'Malibu', 'Tahoe', 'Suburban', 'Traverse', 'Cruze', 'Impala', 'Colorado', 'Camaro'],
        'Chrysler' => ['300', 'Pacifica'], 'Dodge' => ['Charger', 'Challenger', 'Durango', 'Grand Caravan', 'Journey'],
        'Ford' => ['F-150', 'F-250 Super Duty', 'Escape', 'Explorer', 'Mustang', 'Expedition', 'Edge', 'Focus', 'Fusion', 'Ranger'],
        'GMC' => ['Sierra 1500', 'Yukon', 'Acadia', 'Terrain', 'Canyon'],
        'Honda' => ['Civic', 'Accord', 'CR-V', 'Pilot', 'Odyssey', 'Fit', 'HR-V', 'Ridgeline'],
        'Hyundai' => ['Elantra', 'Sonata', 'Tucson', 'Santa Fe', 'Kona', 'Palisade'],
        'Jeep' => ['Wrangler', 'Grand Cherokee', 'Compass', 'Cherokee', 'Renegade', 'Gladiator'],
        'Kia' => ['Forte', 'Sorento', 'Sportage', 'Soul', 'Optima', 'K5', 'Telluride'], 'Lexus' => ['ES', 'RX', 'IS'],
        'Mazda' => ['Mazda3', 'MX-5 Miata', 'Mazda6', 'CX-5', 'CX-9'], 'Mercedes-Benz' => ['C-Class', 'E-Class', 'GLC', 'GLE'],
        'Nissan' => ['Altima', 'Sentra', 'Rogue', 'Pathfinder', 'Frontier', 'Murano', 'Maxima', 'Titan'],
        'RAM' => ['1500', '2500', '3500', 'ProMaster'], 'Subaru' => ['Outback', 'Forester', 'Impreza', 'Legacy', 'Crosstrek', 'WRX'],
        'Tesla' => ['Model 3', 'Model Y'],
        'Toyota' => ['Camry', 'Corolla', 'RAV4', 'Highlander', 'Tacoma', 'Tundra', '4Runner', 'Prius', 'Sienna'],
        'Volkswagen' => ['Jetta', 'Tiguan', 'Passat', 'Golf', 'Atlas'],
    ];

    public function run(): void
    {
        $catalog = json_decode(file_get_contents(database_path('seeders/data/vehicles.json')), true, flags: JSON_THROW_ON_ERROR);
        $now = now();
        $added = 0;

        foreach ($catalog as $makeName => $models) {
            $make = VehicleMake::firstOrCreate(['slug' => Str::slug($makeName)], ['name' => $makeName]);
            $existingModels = VehicleModel::where('vehicle_make_id', $make->id)->pluck('id', 'slug');

            foreach ($models as $modelName => $yearList) {
                $slug = Str::slug($modelName) ?: Str::slug('model '.$modelName);
                $modelId = $existingModels[$slug] ?? null;
                if (! $modelId) {
                    $modelId = DB::table('vehicle_models')->insertGetId([
                        'vehicle_make_id' => $make->id, 'name' => $modelName, 'slug' => $slug, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $existingModels[$slug] = $modelId;
                }

                // NULL engine/trim never collide in a unique index, so skip years that already exist ourselves.
                $have = DB::table('vehicles')->where('vehicle_model_id', $modelId)
                    ->whereNull('vehicle_engine_id')->whereNull('trim')->pluck('year')->flip();
                $rows = [];
                foreach (self::years($yearList) as $year) {
                    if (! isset($have[$year])) {
                        $rows[] = ['vehicle_make_id' => $make->id, 'vehicle_model_id' => $modelId, 'year' => $year, 'created_at' => $now, 'updated_at' => $now];
                    }
                }
                if ($rows) {
                    DB::table('vehicles')->insert($rows);
                    $added += count($rows);
                }
            }
        }

        $removed = $this->removeLegacySamples($catalog);
        $this->command?->info("Vehicles: {$added} added, {$removed} old sample models removed.");
    }

    /** "1981-1990,1993,1995-2027" → [1981, …] */
    public static function years(string $list): array
    {
        $out = [];
        foreach (explode(',', $list) as $part) {
            [$from, $to] = array_pad(explode('-', trim($part)), 2, null);
            foreach (range((int) $from, (int) ($to ?? $from)) as $y) {
                $out[] = $y;
            }
        }

        return $out;
    }

    private function removeLegacySamples(array $catalog): int
    {
        $removed = 0;
        foreach (self::LEGACY as $makeName => $models) {
            $make = VehicleMake::where('slug', Str::slug($makeName))->first();
            if (! $make) {
                continue;
            }
            $real = collect(array_keys($catalog[$makeName] ?? []))->map(fn ($m) => Str::slug($m))->flip();

            foreach ($models as $modelName) {
                $slug = Str::slug($modelName);
                if (isset($real[$slug])) {
                    continue; // still a real model — keep it and its years
                }
                $model = VehicleModel::where('vehicle_make_id', $make->id)->where('slug', $slug)->first();
                if (! $model) {
                    continue;
                }
                $vehicleIds = DB::table('vehicles')->where('vehicle_model_id', $model->id)->pluck('id');
                $inUse = DB::table('product_fitments')->whereIn('vehicle_id', $vehicleIds)->exists()
                    || DB::table('order_items')->whereIn('fit_vehicle_id', $vehicleIds)->exists();
                if ($inUse) {
                    continue;
                }
                DB::table('vehicles')->whereIn('id', $vehicleIds)->delete();
                $model->delete();
                $removed++;
            }
        }

        return $removed;
    }
}
