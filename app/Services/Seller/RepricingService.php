<?php

namespace App\Services\Seller;

use App\Models\Product;
use App\Models\RepricingRule;

class RepricingService
{
    public function __construct(private PriceIntelligenceService $intel) {}

    /**
     * Work out the target price for a rule. Returns null when there's no
     * competitor data to act on.
     */
    public function targetPrice(RepricingRule $rule): ?float
    {
        $product = $rule->product;
        $intel = $this->intel->forProduct($product);

        if (! $intel) {
            return null;
        }

        $lowest = $intel['min'];

        $target = match ($rule->strategy) {
            'match_lowest' => $lowest,
            'beat_lowest' => $lowest - (float) $rule->amount,
            'fixed_above_lowest' => $lowest + (float) $rule->amount,
        };

        $target = max($target, (float) $rule->floor_price);
        if ($rule->ceiling_price !== null) {
            $target = min($target, (float) $rule->ceiling_price);
        }

        return round(max(0.01, $target), 2);
    }

    /** Apply a single rule. Returns a human-readable result string. */
    public function apply(RepricingRule $rule): string
    {
        $target = $this->targetPrice($rule);
        $current = (float) $rule->product->price;

        if ($target === null) {
            $result = 'No competing listings — left at $'.number_format($current, 2);
        } elseif (abs($target - $current) < 0.01) {
            $result = 'Already at $'.number_format($current, 2);
        } else {
            $rule->product->update(['price' => $target]);
            $dir = $target < $current ? 'Lowered' : 'Raised';
            $result = "{$dir} \$".number_format($current, 2).' → $'.number_format($target, 2);
        }

        $rule->forceFill(['last_run_at' => now(), 'last_result' => $result])->save();

        return $result;
    }

    /** Run every active rule (scheduled). */
    public function runAll(): int
    {
        $count = 0;
        RepricingRule::where('is_active', true)
            ->with('product')
            ->chunk(200, function ($rules) use (&$count) {
                foreach ($rules as $rule) {
                    if ($rule->product && $rule->product->status === 'active') {
                        $this->apply($rule);
                        $count++;
                    }
                }
            });

        return $count;
    }
}
