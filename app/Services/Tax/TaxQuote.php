<?php

namespace App\Services\Tax;

/** The sales tax on one order: the total, the part that is on shipping, and each line's share. */
final class TaxQuote
{
    /** @param  array<int|string, float>  $byLine  tax per line, using the keys passed to the calculation */
    public function __construct(
        public readonly float $total = 0.0,
        public readonly float $shipping = 0.0,
        public readonly array $byLine = [],
        public readonly ?string $calculationId = null,
    ) {}
}
