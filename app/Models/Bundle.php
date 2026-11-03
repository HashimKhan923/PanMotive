<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bundle extends Model
{
    protected $fillable = ['seller_id', 'title', 'discount_type', 'discount_value', 'is_active'];

    protected $casts = ['discount_value' => 'decimal:2', 'is_active' => 'boolean'];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BundleItem::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'bundle_items')->withPivot('quantity');
    }

    /** Sum of member products' effective price × pivot qty. */
    public function fullPrice(): float
    {
        return (float) $this->products->sum(fn ($p) => $p->effectivePrice() * $p->pivot->quantity);
    }

    public function discountedPrice(): float
    {
        $full = $this->fullPrice();
        $off = $this->discount_type === 'percentage'
            ? $full * ((float) $this->discount_value / 100)
            : (float) $this->discount_value;

        return round(max(0, $full - $off), 2);
    }

    public function savings(): float
    {
        return round($this->fullPrice() - $this->discountedPrice(), 2);
    }

    /** Every member listing is live and can cover one full set — otherwise don't offer it. */
    public function isAvailable(): bool
    {
        if ($this->products->count() < 2) {
            return false;
        }

        return $this->products->every(fn (Product $p) => $p->status === 'active'
            && $p->sellableQuantity() >= (int) $p->pivot->quantity);
    }
}
