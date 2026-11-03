<?php

namespace App\Services\Seller;

use App\Models\Product;
use App\Models\Vehicle;
use App\Models\VehicleMake;
use Illuminate\Support\Collection;

class FitmentSuggestService
{
    /** Common World Manufacturer Identifier → make name. */
    private const WMI = [
        '1HG' => 'Honda', 'JHM' => 'Honda', '2HG' => 'Honda', 'SHH' => 'Honda',
        '1FA' => 'Ford', '1FT' => 'Ford', '1FM' => 'Ford', '3FA' => 'Ford',
        '1G1' => 'Chevrolet', '1GC' => 'Chevrolet', '2G1' => 'Chevrolet', '3GC' => 'Chevrolet',
        '1C3' => 'Chrysler', '1C4' => 'Jeep', '1C6' => 'Ram', '2C3' => 'Chrysler',
        '4T1' => 'Toyota', '5TD' => 'Toyota', 'JTD' => 'Toyota', '2T1' => 'Toyota', '1NX' => 'Toyota',
        'WBA' => 'BMW', 'WBS' => 'BMW', '5UX' => 'BMW',
        'WDD' => 'Mercedes-Benz', 'WDB' => 'Mercedes-Benz', '4JG' => 'Mercedes-Benz',
        'WVW' => 'Volkswagen', '3VW' => 'Volkswagen', '1VW' => 'Volkswagen',
        'KMH' => 'Hyundai', '5NP' => 'Hyundai', 'KNA' => 'Kia', 'KND' => 'Kia',
        '1N4' => 'Nissan', 'JN1' => 'Nissan', '3N1' => 'Nissan', '5N1' => 'Nissan',
        'JF1' => 'Subaru', '4S3' => 'Subaru', '4S4' => 'Subaru',
    ];

    /** Position-10 VIN year code → model year (2010-2039 window). */
    private function vinYear(string $code): ?int
    {
        $map = [];
        $letters = 'ABCDEFGHJKLMNPRSTVWXY'; // no I,O,Q,U,Z
        foreach (str_split($letters) as $i => $c) {
            $map[$c] = 2010 + $i;               // A=2010 … Y=2030
        }
        foreach (range(1, 9) as $d) {
            $map[(string) $d] = 2030 + $d;       // 1=2031 … 9=2039
        }

        return $map[strtoupper($code)] ?? null;
    }

    /** Vehicle IDs already tagged on other sellers' listings of the same part. */
    public function fromPartNumber(?string $partNumber, ?string $oemNumber = null): Collection
    {
        $pn = trim((string) $partNumber);
        $oem = trim((string) $oemNumber);
        if ($pn === '' && $oem === '') {
            return collect();
        }

        return Product::query()
            ->where('status', 'active')
            ->where(function ($q) use ($pn, $oem) {
                if ($pn !== '') {
                    $q->where('part_number', $pn);
                }
                if ($oem !== '') {
                    $q->orWhere('oem_number', $oem);
                }
            })
            ->with('fitments:id')
            ->get()
            ->flatMap->fitments
            ->pluck('id')
            ->unique()
            ->values();
    }

    /** @return array{ok:bool, label?:string, year?:int, make?:string, vehicle_ids:array<int,int>} */
    public function fromVin(string $vin): array
    {
        $vin = strtoupper(preg_replace('/[^A-Z0-9]/', '', $vin));
        if (strlen($vin) !== 17) {
            return ['ok' => false, 'vehicle_ids' => []];
        }

        $make = self::WMI[substr($vin, 0, 3)] ?? null;
        $year = $this->vinYear($vin[9]);

        if (! $make || ! $year) {
            return ['ok' => false, 'vehicle_ids' => []];
        }

        $makeId = VehicleMake::where('name', $make)->value('id');
        $ids = $makeId
            ? Vehicle::where('vehicle_make_id', $makeId)->where('year', $year)->pluck('id')->all()
            : [];

        return [
            'ok' => true,
            'label' => "{$year} {$make}",
            'year' => $year,
            'make' => $make,
            'vehicle_ids' => $ids,
        ];
    }
}
