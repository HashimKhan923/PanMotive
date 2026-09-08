<?php

namespace App\Support;

use Illuminate\Contracts\Session\Session;

/**
 * Minimal session-backed cart. This is the bridge that lets checkout produce a
 * real Order with snapshotted commission (Phase 1 design) — a fuller cart
 * (saved carts, guest merge, quantity limits) is a later pass.
 *
 * Session shape: ['cart' => [<productId> => <quantity>, ...]]
 */
class Cart
{
    private const KEY = 'cart';

    public function __construct(private Session $session) {}

    /**
     * @return array<int, array{product_id: int, quantity: int}>
     */
    public function items(): array
    {
        $raw = $this->session->get(self::KEY, []);

        return collect($raw)
            ->map(fn ($qty, $id) => ['product_id' => (int) $id, 'quantity' => (int) $qty])
            ->values()
            ->all();
    }

    public function add(int $productId, int $quantity = 1): void
    {
        $raw = $this->session->get(self::KEY, []);
        $raw[$productId] = max(1, ($raw[$productId] ?? 0) + $quantity);
        $this->session->put(self::KEY, $raw);
    }

    public function setQuantity(int $productId, int $quantity): void
    {
        $raw = $this->session->get(self::KEY, []);

        if ($quantity <= 0) {
            unset($raw[$productId]);
        } else {
            $raw[$productId] = $quantity;
        }

        $this->session->put(self::KEY, $raw);
    }

    public function remove(int $productId): void
    {
        $raw = $this->session->get(self::KEY, []);
        unset($raw[$productId]);
        $this->session->put(self::KEY, $raw);
    }

    public function clear(): void
    {
        $this->session->forget(self::KEY);
    }

    public function isEmpty(): bool
    {
        return $this->items() === [];
    }

    public function count(): int
    {
        return array_sum($this->session->get(self::KEY, []));
    }
}
