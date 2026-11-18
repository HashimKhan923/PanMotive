<?php

namespace App\Services\Seller\Import;

use App\Models\Vehicle;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Support\Str;

/**
 * Finds the vehicles in our catalog that an imported part fits, from Year/Make/Model columns or
 * from text such as "2018-2022 Honda Accord; 2019 Toyota Camry" (also "Honda Accord 2018-2022").
 * Only makes and models that exist in the catalog are matched, so nothing is guessed.
 */
class FitmentMatcher
{
    /** @var array<string,int>|null lower-case make name => id */
    private ?array $makes = null;

    /** @var array<int,array<string,int>> make id => [lower-case model name => id] */
    private array $models = [];

    /** @return list<int> vehicle ids */
    public function fromColumns(?string $year, ?string $make, ?string $model): array
    {
        if (! $make || ! $model) {
            return [];
        }
        $makeId = $this->makeId($make);
        $modelId = null;
        if ($makeId) {
            foreach ($this->modelsOf($makeId) as $name => $id) {
                if (self::compact($name) === self::compact($model)) {
                    $modelId = $id;
                    break;
                }
            }
        }
        if (! $modelId) {
            return [];
        }
        [$from, $to] = $this->years($year);

        return $this->vehicles($modelId, $from, $to);
    }

    /** @return list<int> vehicle ids */
    public function fromText(?string $text): array
    {
        $text = trim((string) $text);
        if ($text === '' || $this->allMakes() === []) {
            return [];
        }
        $ids = [];
        foreach (preg_split('/\s*[;|\n]\s*|\s*,\s*(?=\d{4}\b)/', $text) ?: [] as $segment) {
            foreach ($this->parseSegment($segment) as $id) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /** @return list<int> */
    private function parseSegment(string $segment): array
    {
        $yr = '(\d{4})(?:\s*(?:-|–|to|thru|through)\s*(\d{2,4}))?';
        $names = array_keys($this->allMakes());
        usort($names, fn ($a, $b) => strlen($b) <=> strlen($a)); // "mercedes benz" before "mercedes"
        $makeAlt = implode('|', array_map(fn ($m) => preg_quote($m, '/'), $names));

        // "2018-2022 Honda Accord …"
        if (preg_match('/\b'.$yr.'\s+('.$makeAlt.')\b\s*(.+)?/i', $segment, $m)) {
            return $this->match($m[3], (string) ($m[4] ?? ''), $m[1], $m[2] ?? null);
        }
        // "Honda Accord 2018-2022"
        if (preg_match('/\b('.$makeAlt.')\b\s+(.+?)\s+'.$yr.'\b/i', $segment, $m)) {
            return $this->match($m[1], $m[2], $m[3], $m[4] ?? null);
        }

        return [];
    }

    /** @return list<int> */
    private function match(string $make, string $rest, string $from, ?string $to): array
    {
        $makeId = $this->makeId($make);
        if (! $makeId) {
            return [];
        }
        // Longest model name that starts the remaining text ("Civic Type R" before "Civic"). Spaces and
        // hyphens don't matter, so "F150", "F-150" and "f 150" all find the F-150.
        $words = preg_split('/[\s,()]+/', Str::lower(trim($rest)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $byCompact = [];
        foreach ($this->modelsOf($makeId) as $name => $modelId) {
            $byCompact[self::compact($name)] ??= $modelId;
        }
        for ($n = min(4, count($words)); $n >= 1; $n--) {
            $modelId = $byCompact[self::compact(implode(' ', array_slice($words, 0, $n)))] ?? null;
            if ($modelId) {
                [$y1, $y2] = $this->years($from.($to ? '-'.$to : ''));

                return $this->vehicles($modelId, $y1, $y2);
            }
        }

        return [];
    }

    private static function compact(string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower($s));
    }

    /** "2015-2020", "15-20", "2018", "2016, 2017" → [from, to]; nothing → all years. */
    private function years(?string $value): array
    {
        preg_match_all('/\d{2,4}/', (string) $value, $m);
        $nums = array_map(function ($n) {
            $n = (int) $n;

            return $n < 100 ? ($n > (int) date('y') + 1 ? 1900 + $n : 2000 + $n) : $n;
        }, $m[0]);
        $nums = array_values(array_filter($nums, fn ($n) => $n >= 1950 && $n <= (int) date('Y') + 2));
        if ($nums === []) {
            return [null, null];
        }
        [$from, $to] = [min($nums), max($nums)];

        return $to - $from > 40 ? [$from, $from] : [$from, $to];
    }

    /** @return list<int> */
    private function vehicles(int $modelId, ?int $from, ?int $to): array
    {
        return Vehicle::where('vehicle_model_id', $modelId)
            ->when($from, fn ($q) => $q->whereBetween('year', [$from, $to ?? $from]))
            ->limit(500)->pluck('id')->all();
    }

    private function makeId(string $make): ?int
    {
        $key = Str::lower(trim($make));

        return $this->allMakes()[$key] ?? $this->allMakes()[str_replace('-', ' ', Str::slug($key))] ?? null;
    }

    /** @return array<string,int> */
    private function allMakes(): array
    {
        if ($this->makes === null) {
            $this->makes = [];
            foreach (VehicleMake::get(['id', 'name']) as $mk) {
                $this->makes[Str::lower($mk->name)] = $mk->id;
            }
            // Common short forms.
            foreach (['chevy' => 'chevrolet', 'vw' => 'volkswagen', 'mercedes benz' => 'mercedes-benz', 'mercedes' => 'mercedes-benz', 'benz' => 'mercedes-benz'] as $alias => $name) {
                if (isset($this->makes[$name]) && ! isset($this->makes[$alias])) {
                    $this->makes[$alias] = $this->makes[$name];
                }
            }
        }

        return $this->makes;
    }

    /** @return array<string,int> */
    private function modelsOf(int $makeId): array
    {
        return $this->models[$makeId] ??= VehicleModel::where('vehicle_make_id', $makeId)->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [Str::lower($name) => $id])->all();
    }
}
