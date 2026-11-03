<?php

namespace Database\Seeders;

use App\Models\Vehicle;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class VehicleMakeSeeder extends Seeder
{
    /**
     * Popular US makes and models, 2010-2025, one vehicle per model and year. Engines are left
     * blank rather than guessed, so a part's fitment never claims an engine nobody checked.
     * Gaps in a range are years the model wasn't sold in the US.
     */
    public function run(): void
    {
        foreach ($this->catalog() as $makeName => $models) {
            $make = VehicleMake::firstOrCreate(['slug' => Str::slug($makeName)], ['name' => $makeName]);

            foreach ($models as $modelName => $ranges) {
                $model = VehicleModel::firstOrCreate(
                    ['vehicle_make_id' => $make->id, 'slug' => Str::slug($modelName)],
                    ['name' => $modelName]
                );

                foreach ($ranges as [$from, $to]) {
                    foreach (range($from, $to) as $year) {
                        Vehicle::firstOrCreate(
                            ['vehicle_model_id' => $model->id, 'year' => $year],
                            ['vehicle_make_id' => $make->id]
                        );
                    }
                }
            }
        }
    }

    private function catalog(): array
    {
        $all = [[2010, 2025]];

        return [
            'Acura' => ['MDX' => $all, 'RDX' => $all, 'TLX' => [[2015, 2025]]],
            'Audi' => ['A4' => $all, 'A6' => $all, 'Q5' => $all, 'Q7' => [[2010, 2015], [2017, 2025]]],
            'BMW' => ['3 Series' => $all, '5 Series' => $all, 'X3' => $all, 'X5' => $all],
            'Buick' => ['Enclave' => $all, 'Encore' => [[2013, 2025]]],
            'Cadillac' => ['Escalade' => $all, 'XT5' => [[2017, 2025]]],
            'Chevrolet' => [
                'Silverado 1500' => $all, 'Silverado 2500 HD' => $all, 'Equinox' => $all, 'Malibu' => [[2010, 2024]],
                'Tahoe' => $all, 'Suburban' => $all, 'Traverse' => $all, 'Cruze' => [[2011, 2019]],
                'Impala' => [[2010, 2020]], 'Colorado' => [[2010, 2012], [2015, 2025]], 'Camaro' => [[2010, 2024]],
            ],
            'Chrysler' => ['300' => [[2010, 2023]], 'Pacifica' => [[2017, 2025]]],
            'Dodge' => [
                'Charger' => [[2010, 2023]], 'Challenger' => [[2010, 2023]], 'Durango' => [[2011, 2025]],
                'Grand Caravan' => [[2010, 2020]], 'Journey' => [[2010, 2020]],
            ],
            'Ford' => [
                'F-150' => $all, 'F-250 Super Duty' => $all, 'Escape' => $all, 'Explorer' => $all, 'Mustang' => $all,
                'Expedition' => $all, 'Edge' => [[2010, 2024]], 'Focus' => [[2010, 2018]], 'Fusion' => [[2010, 2020]],
                'Ranger' => [[2010, 2011], [2019, 2025]],
            ],
            'GMC' => ['Sierra 1500' => $all, 'Yukon' => $all, 'Acadia' => $all, 'Terrain' => $all, 'Canyon' => [[2010, 2012], [2015, 2025]]],
            'Honda' => [
                'Civic' => $all, 'Accord' => $all, 'CR-V' => $all, 'Pilot' => $all, 'Odyssey' => $all,
                'Fit' => [[2010, 2020]], 'HR-V' => [[2016, 2025]], 'Ridgeline' => [[2010, 2014], [2017, 2025]],
            ],
            'Hyundai' => ['Elantra' => $all, 'Sonata' => $all, 'Tucson' => $all, 'Santa Fe' => $all, 'Kona' => [[2018, 2025]], 'Palisade' => [[2020, 2025]]],
            'Jeep' => [
                'Wrangler' => $all, 'Grand Cherokee' => $all, 'Compass' => $all, 'Cherokee' => [[2014, 2023]],
                'Renegade' => [[2015, 2023]], 'Gladiator' => [[2020, 2025]],
            ],
            'Kia' => [
                'Forte' => $all, 'Sorento' => $all, 'Sportage' => $all, 'Soul' => $all, 'Optima' => [[2010, 2020]],
                'K5' => [[2021, 2025]], 'Telluride' => [[2020, 2025]],
            ],
            'Lexus' => ['ES' => $all, 'RX' => $all, 'IS' => $all],
            'Mazda' => ['Mazda3' => $all, 'MX-5 Miata' => $all, 'Mazda6' => [[2010, 2021]], 'CX-5' => [[2013, 2025]], 'CX-9' => [[2010, 2023]]],
            'Mercedes-Benz' => ['C-Class' => $all, 'E-Class' => $all, 'GLC' => [[2016, 2025]], 'GLE' => [[2016, 2025]]],
            'Nissan' => [
                'Altima' => $all, 'Sentra' => $all, 'Rogue' => $all, 'Pathfinder' => $all, 'Frontier' => $all, 'Murano' => $all,
                'Maxima' => [[2010, 2023]], 'Titan' => [[2010, 2024]],
            ],
            'RAM' => ['1500' => [[2011, 2025]], '2500' => [[2011, 2025]], '3500' => [[2011, 2025]], 'ProMaster' => [[2014, 2025]]],
            'Subaru' => ['Outback' => $all, 'Forester' => $all, 'Impreza' => $all, 'Legacy' => $all, 'Crosstrek' => [[2013, 2025]], 'WRX' => [[2015, 2025]]],
            'Tesla' => ['Model 3' => [[2017, 2025]], 'Model Y' => [[2020, 2025]]],
            'Toyota' => [
                'Camry' => $all, 'Corolla' => $all, 'RAV4' => $all, 'Highlander' => $all, 'Tacoma' => $all,
                'Tundra' => $all, '4Runner' => $all, 'Prius' => $all, 'Sienna' => $all,
            ],
            'Volkswagen' => ['Jetta' => $all, 'Tiguan' => $all, 'Passat' => [[2010, 2022]], 'Golf' => [[2010, 2021]], 'Atlas' => [[2018, 2025]]],
        ];
    }
}
