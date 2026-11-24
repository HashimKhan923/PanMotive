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

    /**
     * A product title such as "HRS 2023-26 11th Gen Honda Accord Front Lip" or
     * "2022-26 Toyota GR86 / Subaru BRZ Tail Lights": one year range anywhere in the title, then every
     * make + model it names ("Model 3/Y" means both). Falls back to fromText() for other layouts.
     *
     * @return list<int> vehicle ids
     */
    public function fromTitle(?string $title): array
    {
        $title = trim((string) $title);
        if ($title === '' || $this->allMakes() === []) {
            return [];
        }
        // the year range: "2018-2022", "2018-22", "2018–22", "17-21" or a single "2019"
        if (preg_match('/\b((?:19|20)\d{2})\s*(?:-|–|—|to|thru)\s*((?:19|20)?\d{2})\b/u', $title, $m)) {
            [$from, $to] = $this->years($m[1].'-'.$m[2]);
        } elseif (preg_match('/(?:^|\s)(\d{2})\s*[-–]\s*(\d{2})(?=\s)/u', $title, $m) && (int) $m[1] < (int) $m[2]) {
            [$from, $to] = $this->years($m[1].'-'.$m[2]);
        } elseif (preg_match('/\b((?:19|20)\d{2})\b/', $title, $m)) {
            [$from, $to] = [(int) $m[1], (int) $m[1]];
        } else {
            return $this->fromText($title);
        }
        if (! $from) {
            return [];
        }

        $names = array_keys($this->allMakes());
        usort($names, fn ($a, $b) => strlen($b) <=> strlen($a));
        $makeAlt = implode('|', array_map(fn ($m) => preg_quote($m, '/'), $names));
        $ids = [];
        if (preg_match_all('/\b('.$makeAlt.')\b/iu', $title, $all, PREG_OFFSET_CAPTURE)) {
            foreach ($all[1] as [$make, $at]) {
                // the words after this make, up to a "|" (several makes in one title each get their own look)
                $rest = (string) strtok(substr($title, $at + strlen($make)), '|');
                $makeId = $this->makeId($make);
                if (! $makeId) {
                    continue;
                }
                // "Model 3/Y": the shared first word goes with every part after a slash
                $pieces = preg_split('/\s*\/\s*/', trim($rest)) ?: [];
                $first = preg_split('/\s+/', trim($pieces[0] ?? '')) ?: [];
                foreach ($pieces as $i => $piece) {
                    $tries = [$piece];
                    if ($i > 0 && count($first) > 1 && preg_match('/^\w{1,2}\b/', $piece)) {
                        $tries[] = $first[0].' '.$piece;
                    }
                    foreach ($tries as $try) {
                        $modelIds = $this->modelsAtStart($makeId, $try);
                        foreach ($modelIds as $modelId) {
                            foreach ($this->vehicles($modelId, $from, $to) as $id) {
                                $ids[$id] = true;
                            }
                        }
                        if ($modelIds) {
                            break;
                        }
                    }
                    if ($i === 0 && ! $this->modelsAtStart($makeId, $piece)) {
                        break;   // the slash wasn't a list of models
                    }
                }
            }
        }

        // "Civic … FK8 Type-R …": the Type R is its own model ("Type R Style" is only a look)
        if ($ids && preg_match('/\bcivic\b/i', $title) && preg_match('/\btype[- ]r\b(?!\s+style)/i', $title)
            && ($honda = $this->makeId('honda')) && ($typeR = $this->modelsOf($honda)['civic type r'] ?? null)) {
            foreach ($this->vehicles($typeR, $from, $to) as $id) {
                $ids[$id] = true;
            }
        }

        return $ids ? array_keys($ids) : $this->fromText($title);
    }

    /**
     * Model(s) named at the start of $text for a make: the longest match, so "Civic Type R" beats "Civic".
     * Toyota's "86" became the "GR86" in 2022, so "Toyota 86" also means the GR86.
     *
     * @return list<int> model ids
     */
    private function modelsAtStart(int $makeId, string $text): array
    {
        // hyphens split words too ("Armada-Patrol"); compact() joins them back for "F-150" / "GT-R"
        $words = preg_split('/[\s,()\-]+/', Str::lower(trim($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $models = $this->modelsOf($makeId);
        $byCompact = [];
        foreach ($models as $name => $modelId) {
            $byCompact[self::compact($name)] ??= $modelId;
        }
        for ($n = min(4, count($words)); $n >= 1; $n--) {
            $key = self::compact(implode(' ', array_slice($words, 0, $n)));
            if ($modelId = $byCompact[$key] ?? null) {
                $out = [$modelId];
                if ($key === '86' && isset($byCompact['gr86'])) {
                    $out[] = $byCompact['gr86'];
                }

                return $out;
            }
        }
        // BMW lists cars by trim (330i, 340i…): "3 Series" / "G20" / "F30" means every 3-series sedan
        if (preg_match('/\b(3 series|g20|f30)\b/', Str::lower($text))) {
            $out = [];
            foreach ($models as $name => $modelId) {
                if (preg_match('/^3\d\d[a-z]*$/', (string) $name) && ! preg_match('/(ci|is|ic)$/', (string) $name) && (string) $name !== '335') {
                    $out[] = $modelId;
                }
            }
            if ($out) {
                return $out;
            }
        }
        // a family name without its size ("Silverado" → "Silverado 1500"): the 1500, or the only one
        if ($words) {
            $family = array_filter($models, fn ($id, $name) => str_starts_with((string) $name, $words[0].' '), ARRAY_FILTER_USE_BOTH);
            $half = array_filter($family, fn ($id, $name) => str_contains((string) $name, '1500'), ARRAY_FILTER_USE_BOTH);
            if ($half) {
                return [reset($half)];
            }
            if (count($family) === 1) {
                return [reset($family)];
            }
        }

        return [];
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
