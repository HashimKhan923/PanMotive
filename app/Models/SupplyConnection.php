<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A reseller's link to a supplier in the Supply Network (pending → approved, or declined / ended). */
class SupplyConnection extends Model
{
    public const STATUSES = ['pending', 'approved', 'declined', 'ended'];

    protected $fillable = ['supplier_id', 'reseller_id', 'status', 'margin_pct', 'message', 'decided_at'];

    protected $casts = ['margin_pct' => 'decimal:2', 'decided_at' => 'datetime'];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reseller_id');
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
