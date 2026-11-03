<?php

namespace App\Services\Seller;

use App\Models\AdTransaction;
use App\Models\Boost;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BoostService
{
    /** Flat fee per day, by placement. */
    public const RATES = ['search' => 3.00, 'category' => 2.00, 'home' => 6.00];

    public function quote(string $placement, int $days): float
    {
        return round((self::RATES[$placement] ?? 3.00) * max(1, $days), 2);
    }

    public function grantCredit(User $seller, float $amount, string $description = 'Ad credit top-up', string $type = 'topup'): void
    {
        DB::transaction(function () use ($seller, $amount, $description, $type) {
            $profile = $seller->sellerProfile()->lockForUpdate()->first();
            $balance = round((float) $profile->ad_credit + $amount, 2);
            $profile->update(['ad_credit' => $balance]);

            AdTransaction::create([
                'seller_id' => $seller->id,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $balance,
                'description' => $description,
            ]);
        });

        // Campaigns that stopped only because the wallet ran dry switch back on.
        app(AdServingService::class)->resumeAfterTopUp($seller->id);
    }

    public function start(User $seller, Product $product, string $placement, int $days): Boost
    {
        abort_unless($product->seller_id === $seller->id, 403);
        abort_unless($product->status === 'active', 422, 'Only active listings can be boosted.');

        $placement = array_key_exists($placement, self::RATES) ? $placement : 'search';
        $days = max(1, min(30, $days));
        $fee = $this->quote($placement, $days);

        return DB::transaction(function () use ($seller, $product, $placement, $days, $fee) {
            $profile = $seller->sellerProfile()->lockForUpdate()->first();

            if ((float) $profile->ad_credit < $fee) {
                throw ValidationException::withMessages([
                    'boost' => "Not enough ad credit. This boost costs \${$fee}; you have \${$profile->ad_credit}.",
                ]);
            }

            $balance = round((float) $profile->ad_credit - $fee, 2);
            $profile->update(['ad_credit' => $balance]);

            $boost = Boost::create([
                'product_id' => $product->id,
                'seller_id' => $seller->id,
                'placement' => $placement,
                'days' => $days,
                'fee' => $fee,
                'starts_at' => now(),
                'ends_at' => now()->addDays($days),
                'status' => 'active',
            ]);

            AdTransaction::create([
                'seller_id' => $seller->id,
                'type' => 'boost_spend',
                'amount' => -$fee,
                'balance_after' => $balance,
                'description' => "Boost: {$product->title} ({$placement}, {$days}d)",
                'source_type' => Boost::class,
                'source_id' => $boost->id,
            ]);

            return $boost;
        });
    }

    public function cancel(Boost $boost): void
    {
        if ($boost->status !== 'active') {
            return;
        }

        // Pro-rata refund for unused full days.
        $remaining = max(0, (int) now()->diffInDays($boost->ends_at, false));
        $refund = round(($boost->fee / $boost->days) * min($remaining, $boost->days), 2);

        DB::transaction(function () use ($boost, $refund) {
            $boost->update(['status' => 'cancelled', 'ends_at' => now()]);

            if ($refund > 0) {
                $profile = $boost->seller->sellerProfile()->lockForUpdate()->first();
                $balance = round((float) $profile->ad_credit + $refund, 2);
                $profile->update(['ad_credit' => $balance]);

                AdTransaction::create([
                    'seller_id' => $boost->seller_id,
                    'type' => 'refund',
                    'amount' => $refund,
                    'balance_after' => $balance,
                    'description' => "Refund for cancelled boost #{$boost->id}",
                    'source_type' => Boost::class,
                    'source_id' => $boost->id,
                ]);
            }
        });
    }

    /** Sweep expired boosts to 'ended'. Called by a scheduled command. */
    public function sweepExpired(): int
    {
        return Boost::where('status', 'active')->where('ends_at', '<=', now())->update(['status' => 'ended']);
    }
}
