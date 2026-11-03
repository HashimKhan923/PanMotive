<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreLoyaltyLedgerEntry extends Model
{
    protected $table = 'store_loyalty_ledger';

    protected $fillable = ['store_loyalty_account_id', 'delta', 'balance_after', 'reason', 'order_id'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(StoreLoyaltyAccount::class, 'store_loyalty_account_id');
    }
}
