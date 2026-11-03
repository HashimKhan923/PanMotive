<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Affiliate extends Model
{
    protected $fillable = [
        'affiliate_program_id', 'user_id', 'name', 'email', 'code', 'channel',
        'status', 'clicks', 'conversions', 'earnings', 'paid_out',
    ];

    protected $casts = ['earnings' => 'decimal:2', 'paid_out' => 'decimal:2'];

    protected static function booted(): void
    {
        static::creating(fn (Affiliate $a) => $a->code ??= strtoupper(Str::random(8)));
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(AffiliateProgram::class, 'affiliate_program_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(AffiliateConversion::class);
    }

    public function unpaidBalance(): float
    {
        return round((float) $this->earnings - (float) $this->paid_out, 2);
    }

    /** Commission cleared the return window and can be paid now. */
    public function payableBalance(): float
    {
        return round((float) AffiliateConversion::where('affiliate_id', $this->id)->where('status', 'approved')->sum('commission'), 2);
    }

    /** Commission on orders still inside the return window (not payable yet). */
    public function pendingBalance(): float
    {
        return round((float) AffiliateConversion::where('affiliate_id', $this->id)->where('status', 'pending')->sum('commission'), 2);
    }
}
