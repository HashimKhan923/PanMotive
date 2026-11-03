<?php

namespace App\Services\Seller;

use App\Models\Category;
use App\Services\AI\AiClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Maps free-text category hints from an imported CSV (Shopify "Product Category",
 * a "Type" column, tags, or just the product title) onto Pan Motive's fixed
 * category tree. Deterministic keyword + fuzzy matching first; an optional AI
 * pass cleans up whatever is left (batched — one call per import).
 */
class CategoryResolver
{
    /** substring (lower-case) => category slug, checked longest-key-first for specificity */
    private const KEYWORDS = [
        'brake pad' => 'brake-pads', 'brake pads' => 'brake-pads', 'disc pad' => 'brake-pads',
        'brake rotor' => 'brake-rotors', 'brake disc' => 'brake-rotors', 'rotor' => 'brake-rotors',
        'brake caliper' => 'calipers', 'caliper' => 'calipers',
        'brake line' => 'brake-lines', 'brake hose' => 'brake-lines',
        'brake kit' => 'brakes', 'brake' => 'brakes',
        'spark plug' => 'spark-plugs', 'sparkplug' => 'spark-plugs', 'glow plug' => 'spark-plugs',
        'oil filter' => 'filters', 'air filter' => 'filters', 'cabin filter' => 'filters',
        'fuel filter' => 'filters', 'filter' => 'filters',
        'timing belt' => 'belts', 'serpentine belt' => 'belts', 'drive belt' => 'belts', 'belt' => 'belts',
        'head gasket' => 'gaskets', 'valve cover gasket' => 'gaskets', 'gasket' => 'gaskets',
        'shock absorber' => 'shocks-struts', 'strut assembly' => 'shocks-struts',
        'coilover' => 'shocks-struts', 'shock' => 'shocks-struts', 'strut' => 'shocks-struts',
        'control arm' => 'control-arms', 'a-arm' => 'control-arms',
        'ball joint' => 'ball-joints',
        'sway bar' => 'suspension', 'tie rod' => 'suspension', 'suspension' => 'suspension',
        'alternator' => 'alternators',
        'starter motor' => 'starters', 'starter' => 'starters',
        'battery' => 'batteries', 'batteries' => 'batteries',
        'oxygen sensor' => 'sensors', 'o2 sensor' => 'sensors', 'abs sensor' => 'sensors',
        'maf sensor' => 'sensors', 'map sensor' => 'sensors', 'speed sensor' => 'sensors',
        'wheel speed sensor' => 'sensors', 'sensor' => 'sensors',
        'radiator' => 'radiators',
        'water pump' => 'water-pumps',
        'thermostat' => 'thermostats',
        'coolant' => 'cooling', 'antifreeze' => 'cooling', 'cooling' => 'cooling',
        'ignition coil' => 'electrical', 'wiring harness' => 'electrical', 'wiring' => 'electrical',
        'fuse' => 'electrical', 'relay' => 'electrical', 'electrical' => 'electrical',
        'head lamp' => 'headlights', 'headlamp' => 'headlights', 'headlight' => 'headlights',
        'tail light' => 'tail-lights', 'taillight' => 'tail-lights', 'tail lamp' => 'tail-lights', 'brake light' => 'tail-lights',
        'led reflector' => 'led-accent-lights', 'reflector' => 'led-accent-lights', 'license plate light' => 'led-accent-lights',
        'fog light' => 'led-accent-lights', 'light bar' => 'led-accent-lights', 'led light' => 'led-accent-lights',
        'mirror' => 'mirrors', 'spoiler' => 'spoilers-wings', 'rear wing' => 'spoilers-wings', 'trunk wing' => 'spoilers-wings', ' wing ' => 'spoilers-wings',
        'bumper' => 'bumpers-grilles', 'grille' => 'bumpers-grilles',
        'piston' => 'engine', 'camshaft' => 'engine', 'crankshaft' => 'engine',
        'valve' => 'engine', 'engine' => 'engine',
    ];

    private ?Collection $categories = null;

    public function __construct(private AiClient $ai) {}

    /**
     * Resolve many distinct hint strings at once.
     *
     * @param  array<int,string>  $hints
     * @param  bool  $useAi  run the AI clean-up pass for anything deterministic matching missed
     * @return array<string,int>  hint (lower-cased, trimmed) => category id  — only successful matches
     */
    public function resolveMany(array $hints, bool $useAi = true): array
    {
        $cats = $this->categories();
        $resolved = [];
        $unresolved = [];

        foreach (array_unique(array_map(fn ($h) => Str::lower(trim($h)), $hints)) as $hint) {
            if ($hint === '') {
                continue;
            }
            $id = $this->matchDeterministic($hint, $cats);
            if ($id) {
                $resolved[$hint] = $id;
            } else {
                $unresolved[] = $hint;
            }
        }

        if ($useAi && $unresolved && $this->ai->isConfigured()) {
            $taxHash = substr(md5($cats->pluck('slug')->sort()->implode(',')), 0, 8);
            $ask = [];

            // Re-use anything we've classified before (a hint string is stable across imports).
            foreach ($unresolved as $hint) {
                $cached = Cache::get("catres:{$taxHash}:".md5($hint));
                if ($cached === '-') {
                    continue;                       // known "no match" — don't ask again
                }
                if ($cached && ($cat = $cats->firstWhere('slug', $cached))) {
                    $resolved[$hint] = $cat->id;
                } else {
                    $ask[] = $hint;
                }
            }

            if ($ask) {
                $answers = $this->matchWithAi($ask, $cats);
                foreach ($ask as $hint) {
                    $slug = $answers[$hint] ?? null;
                    $cat = $slug ? $cats->firstWhere('slug', $slug) : null;
                    Cache::put("catres:{$taxHash}:".md5($hint), $cat?->slug ?? '-', now()->addDays(90));
                    if ($cat) {
                        $resolved[$hint] = $cat->id;
                    }
                }
            }
        }

        return $resolved;
    }

    /** Single-hint convenience (deterministic only — no per-row AI calls). */
    public function resolve(string ...$hints): ?int
    {
        $cats = $this->categories();
        foreach ($hints as $hint) {
            $id = $this->matchDeterministic(Str::lower(trim($hint)), $cats);
            if ($id) {
                return $id;
            }
        }

        return null;
    }

    private function matchDeterministic(string $hint, Collection $cats): ?int
    {
        if ($hint === '') {
            return null;
        }

        // Shopify categories: "Vehicles & Parts > Vehicle Parts > Brake Pads" — keep the leaf and the whole.
        $leaf = Str::lower(trim(Str::afterLast($hint, '>')));
        $candidates = array_unique([$leaf, $hint]);

        // 1. exact name / slug
        foreach ($candidates as $c) {
            $hit = $cats->first(fn ($cat) => Str::lower($cat->name) === $c || $cat->slug === Str::slug($c));
            if ($hit) {
                return $hit->id;
            }
        }

        // 2. a category name appears as a whole phrase inside the hint — most specific (deepest) wins
        $named = $cats
            ->filter(fn ($cat) => Str::contains(" {$hint} ", ' '.Str::lower($cat->name).' ')
                || Str::contains($hint, Str::lower($cat->name)))
            ->sortByDesc(fn ($cat) => ($cat->parent_id ? 10 : 0) + Str::length($cat->name))
            ->first();
        if ($named) {
            return $named->id;
        }

        // 3. keyword table (longest key first)
        $keywords = self::KEYWORDS;
        uksort($keywords, fn ($a, $b) => Str::length($b) <=> Str::length($a));
        foreach ($keywords as $needle => $slug) {
            if (Str::contains($hint, $needle)) {
                $cat = $cats->firstWhere('slug', $slug);
                if ($cat) {
                    return $cat->id;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int,string>  $hints
     * @return array<string,string>  hint => category slug
     */
    private function matchWithAi(array $hints, Collection $cats): array
    {
        $taxonomy = $cats->map(fn ($c) => "- {$c->slug}: {$c->name}".($c->parent_id ? ' (under '.($cats->firstWhere('id', $c->parent_id)->name ?? '').')' : ''))->implode("\n");

        $list = collect($hints)->map(fn ($h) => '- '.$h)->implode("\n");

        $out = $this->ai->completeJson(
            'You classify auto-parts product descriptions into a fixed category taxonomy. '
            .'Return a compact JSON object mapping each input string to the single best category slug from the taxonomy, '
            .'or null if none fit. Use only slugs from the list. No explanation.',
            "Taxonomy:\n{$taxonomy}\n\nClassify these:\n{$list}",
            400,
        );

        $valid = $cats->pluck('slug')->flip();

        return collect($out)
            ->mapWithKeys(fn ($slug, $hint) => [Str::lower(trim((string) $hint)) => (string) $slug])
            ->filter(fn ($slug) => $valid->has($slug))
            ->all();
    }

    private function categories(): Collection
    {
        return $this->categories ??= Category::query()
            ->when(\Illuminate\Support\Facades\Schema::hasColumn('categories', 'is_active'),
                fn ($q) => $q->where('is_active', true))
            ->get(['id', 'name', 'slug', 'parent_id']);
    }
}
