<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;

/** Cookie-backed list of recently viewed product ids (max 12). */
class RecentlyViewedService
{
    private const COOKIE = 'pm_recent';
    private const MAX = 12;

    public function push(int $productId): void
    {
        $ids = array_values(array_unique(array_merge([$productId], $this->rawIds())));
        $ids = array_slice($ids, 0, self::MAX);
        Cookie::queue(self::COOKIE, implode(',', $ids), 60 * 24 * 30);
    }

    /** @return array<int,int> */
    public function rawIds(): array
    {
        $raw = request()->cookie(self::COOKIE, '');

        return collect(explode(',', (string) $raw))->filter()->map(fn ($v) => (int) $v)->all();
    }

    public function products(?int $excludeId = null, int $limit = 6): Collection
    {
        $ids = collect($this->rawIds())->reject(fn ($id) => $id === $excludeId)->take($limit + 4)->all();
        if (! $ids) {
            return collect();
        }

        return Product::whereIn('id', $ids)->listed()->with('brand')
            ->get()->sortBy(fn ($p) => array_search($p->id, $ids))->take($limit)->values();
    }
}
