<?php

namespace App\Services\Marketing;

use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WalletService
{
    public function credit(User $user, float $amount, string $type, string $description, ?Model $source = null): CreditTransaction
    {
        return DB::transaction(function () use ($user, $amount, $type, $description, $source) {
            $user->refresh(); // avoid stale balance under concurrent writes
            $newBalance = round((float) $user->wallet_balance + $amount, 2);
            $user->update(['wallet_balance' => $newBalance]);

            return CreditTransaction::create([
                'user_id' => $user->id,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $newBalance,
                'description' => $description,
                'source_type' => $source ? $source::class : null,
                'source_id' => $source?->getKey(),
            ]);
        });
    }

    public function debit(User $user, float $amount, string $description, ?Model $source = null): CreditTransaction
    {
        if ((float) $user->wallet_balance < $amount) {
            throw new RuntimeException('Insufficient credit balance.');
        }

        return $this->credit($user, -$amount, 'redeemed', $description, $source);
    }

    /**
     * Cap redemption at 50% of order value — no fully credit-funded orders.
     */
    public function maxRedeemable(User $user, float $orderSubtotal): float
    {
        return round(min((float) $user->wallet_balance, $orderSubtotal * 0.5), 2);
    }
}
