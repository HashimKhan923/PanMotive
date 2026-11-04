<?php

namespace App\Services\Marketing;

use App\Models\Order;
use App\Models\SellerProfile;
use App\Models\StoreLoyaltyAccount;
use App\Models\User;
use App\Services\Marketing\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreLoyaltyService
{
    public function __construct(private WalletService $wallet) {}

    public const DEFAULTS = [
        'enabled' => false,
        'points_per_dollar' => 5,
        'redeem_cents_per_point' => 1,   // 100 points = $1.00
        'min_redeem' => 500,
    ];

    public function config(SellerProfile $profile): array
    {
        return array_merge(self::DEFAULTS, $profile->loyalty ?? []);
    }

    public function account(User $user, int $sellerId): StoreLoyaltyAccount
    {
        return StoreLoyaltyAccount::firstOrCreate(['user_id' => $user->id, 'seller_id' => $sellerId]);
    }

    /** Award points for each seller's portion of a delivered order (idempotent per order+seller). */
    public function earnForOrder(Order $order): void
    {
        $order->loadMissing('items.seller.sellerProfile', 'buyer');
        if (! $order->buyer) {
            return;
        }

        foreach ($order->items->groupBy('seller_id') as $sellerId => $items) {
            $profile = $items->first()->seller?->sellerProfile;
            if (! $profile) {
                continue;
            }
            $cfg = $this->config($profile);
            if (! $cfg['enabled']) {
                continue;
            }

            $account = $this->account($order->buyer, (int) $sellerId);
            if ($account->ledger()->where('order_id', $order->id)->where('delta', '>', 0)->exists()) {
                continue;
            }

            $spend = (float) $items->sum('line_total');
            $points = (int) floor($spend * $cfg['points_per_dollar']);
            if ($points > 0) {
                $account->move($points, "Earned on order {$order->order_number}", $order->id);
            }
        }
    }

    /** Convert store points into PanMotive wallet credit. */
    public function redeemToWallet(User $user, int $sellerId, int $points): float
    {
        $profile = User::find($sellerId)?->sellerProfile;
        abort_unless($profile, 404);
        $cfg = $this->config($profile);
        $account = $this->account($user, $sellerId);

        if (! $cfg['enabled'] || $points < $cfg['min_redeem'] || $points > $account->points) {
            throw ValidationException::withMessages(['points' => "Enter between {$cfg['min_redeem']} and {$account->points} points."]);
        }

        $value = round($points * $cfg['redeem_cents_per_point'] / 100, 2);

        DB::transaction(function () use ($account, $points, $value, $profile, $user) {
            $account->move(-$points, 'Redeemed for store credit');
            $this->wallet->credit($user, $value, 'redeemed', "Redeemed {$profile->business_name} points");
        });

        return $value;
    }
}
