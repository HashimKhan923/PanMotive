<?php

namespace App\Services\Search;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * What a shopper's search words match. Every word must match somewhere — title, part numbers,
 * brand, category or fitment — so "bosch brake pads" and "2024 honda accord" both work.
 *
 * All filtering and sorting stays in the database, so results are always current. When Meilisearch
 * is reachable it only widens the match (typo tolerance); when it's down, a five-minute breaker
 * stops every search waiting on its connection timeout.
 */
class CatalogSearch
{
    private const STOP_WORDS = ['a', 'an', 'and', 'the', 'for', 'of', 'in', 'to', 'with', 'my', 'on', 'part', 'parts'];

    private const BREAKER_KEY = 'search.engine_down';

    /** @var array<string, list<int|string>> */
    private array $engineMemo = [];

    /** @return list<string> lower-case words, singularised ("rotors" → "rotor") */
    public function tokens(string $term): array
    {
        $words = preg_split('/[\s,\/|]+/u', Str::lower(trim($term))) ?: [];

        return collect($words)
            ->map(fn ($w) => trim($w, " \t\"'().:;!?–—-"))
            ->reject(fn ($w) => $w === '' || in_array($w, self::STOP_WORDS, true))
            ->map(fn ($w) => strlen($w) > 3 && str_ends_with($w, 's') && ! str_ends_with($w, 'ss') ? substr($w, 0, -1) : $w)
            ->unique()->take(8)->values()->all();
    }

    public function apply(Builder $query, string $term): Builder
    {
        $tokens = $this->tokens($term);
        if ($tokens === []) {
            // Nothing but filler ("parts") narrows nothing; bare punctuation can't match anything.
            return trim($term) === '' || preg_match('/[\pL\pN]/u', $term) ? $query : $query->whereRaw('1 = 0');
        }

        $engineIds = $this->engineIds($term);

        return $query->where(function (Builder $outer) use ($tokens, $engineIds) {
            $outer->where(function (Builder $all) use ($tokens) {
                foreach ($tokens as $token) {
                    $all->where(fn (Builder $w) => $this->matchToken($w, $token));
                }
            });
            if ($engineIds) {
                $outer->orWhereIn('products.id', $engineIds);
            }
        });
    }

    /** Best matches first: exact part number, then the phrase in the title, then the rest. */
    public function orderByRelevance(Builder $query, string $term): Builder
    {
        $term = trim($term);
        if ($term === '') {
            return $query;
        }
        $like = '%'.$this->escape(Str::lower($term)).'%';

        return $query->orderByRaw(
            "CASE WHEN LOWER(products.part_number) = ? OR LOWER(products.oem_number) = ? OR LOWER(products.sku) = ? THEN 0
                  WHEN LOWER(products.title) LIKE ? ESCAPE '!' THEN 1
                  ELSE 2 END",
            [Str::lower($term), Str::lower($term), Str::lower($term), $like]
        );
    }

    private function matchToken(Builder $w, string $token): void
    {
        // Year words also match fitment years, year ranges in titles ("2023-26") and the description.
        if (preg_match('/^(19|20)\d{2}$/', $token)) {
            $w->orWhereHas('fitments', fn ($f) => $f->where('year', (int) $token))
                ->orWhereRaw("products.description LIKE ? ESCAPE '!'", ['%'.$this->escape($token).'%']);
            if ($ids = $this->idsWithYearInTitleRange((int) $token)) {
                $w->orWhereIn('products.id', $ids);
            }
        }

        $this->orWord($w, 'products.title', $token);

        // Part numbers ignore dashes and spaces, so "BP2517" finds "BP-2517". Too short means noise.
        if (strlen($token) >= 3) {
            $bare = '%'.$this->escape(preg_replace('/[^a-z0-9]/', '', $token) ?: $token).'%';
            foreach (['products.part_number', 'products.oem_number', 'products.sku'] as $column) {
                $w->orWhereRaw("REPLACE(REPLACE(LOWER({$column}), '-', ''), ' ', '') LIKE ? ESCAPE '!'", [$bare]);
            }
        }

        $w->orWhereHas('brand', fn ($b) => $b->where(fn ($n) => $this->orWord($n, 'brands.name', $token)))
            ->orWhereHas('category', fn ($c) => $c->where(fn ($n) => $this->orWord($n, 'categories.name', $token))
                ->orWhereHas('parent', fn ($p) => $p->where(fn ($n) => $this->orWord($n, 'categories.name', $token))));

        // Make and model words only: a bare number shouldn't match every "Model 3".
        if (! is_numeric($token)) {
            $w->orWhereHas('fitments', fn ($f) => $f
                ->whereHas('make', fn ($m) => $m->where(fn ($n) => $this->orWord($n, 'vehicle_makes.name', $token)))
                ->orWhereHas('model', fn ($m) => $m->where(fn ($n) => $this->orWord($n, 'vehicle_models.name', $token))));
        }
    }

    /** Short words must start a word: "led" shouldn't hit "sealed", nor "ac" hit "Tacoma". */
    private function orWord($query, string $column, string $token): void
    {
        $escaped = $this->escape($token);
        $patterns = strlen($token) > 3
            ? ["%{$escaped}%"]
            : ["{$escaped}%", "% {$escaped}%", "%-{$escaped}%", "%/{$escaped}%", "%({$escaped}%"];

        foreach ($patterns as $pattern) {
            $query->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$pattern]);
        }
    }

    /** Listings whose title gives a year range covering $year, e.g. "HRS 2023-26" or "2018–2026". */
    private function idsWithYearInTitleRange(int $year): array
    {
        $ranges = Cache::remember('search.title_year_ranges', 600, function () {
            $out = [];
            Product::where('status', 'active')->where(fn ($q) => $q->where('title', 'like', '%19%')->orWhere('title', 'like', '%20%'))->select('id', 'title')
                ->chunkById(1000, function ($rows) use (&$out) {
                    foreach ($rows as $p) {
                        if (preg_match('/\b((?:19|20)\d{2})\s*[-–]\s*((?:19|20)?\d{2})\b/u', $p->title, $m)) {
                            $to = (int) (strlen($m[2]) === 2 ? substr($m[1], 0, 2).$m[2] : $m[2]);
                            $out[$p->id] = [(int) $m[1], $to];
                        }
                    }
                });

            return $out;
        });

        return array_keys(array_filter($ranges, fn ($r) => $year >= $r[0] && $year <= $r[1]));
    }

    /** IDs Meilisearch considers a match, or [] when it isn't configured or is unreachable. */
    private function engineIds(string $term): array
    {
        if (config('scout.driver') !== 'meilisearch' || Cache::has(self::BREAKER_KEY)) {
            return [];
        }
        if (isset($this->engineMemo[$term])) {
            return $this->engineMemo[$term];
        }

        try {
            // Every word must match, as in the database path; Meilisearch's default drops words.
            return $this->engineMemo[$term] = Product::search($term, fn ($meilisearch, $query, $options) => $meilisearch
                ->search($query, $options + ['matchingStrategy' => 'all']))->take(300)->keys()->all();
        } catch (\Throwable $e) {
            report($e);
            Cache::put(self::BREAKER_KEY, true, now()->addMinutes(5));

            return [];
        }
    }

    /** LIKE treats % and _ as wildcards; a search for "50%" must mean the literal text. */
    private function escape(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
