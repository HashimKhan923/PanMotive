<?php

namespace App\Support;

/** Session-backed product comparison list (max 4). */
class Compare
{
    public const MAX = 4;

    private const KEY = 'compare';

    public function ids(): array
    {
        return array_values(array_unique(session(self::KEY, [])));
    }

    public function has(int $productId): bool
    {
        return in_array($productId, $this->ids(), true);
    }

    public function count(): int
    {
        return count($this->ids());
    }

    /** @return bool true if now in the list */
    public function toggle(int $productId): bool
    {
        $ids = $this->ids();

        if (in_array($productId, $ids, true)) {
            session([self::KEY => array_values(array_diff($ids, [$productId]))]);

            return false;
        }

        if (count($ids) >= self::MAX) {
            return false;
        }

        $ids[] = $productId;
        session([self::KEY => $ids]);

        return true;
    }

    public function remove(int $productId): void
    {
        session([self::KEY => array_values(array_diff($this->ids(), [$productId]))]);
    }

    public function clear(): void
    {
        session()->forget(self::KEY);
    }
}
