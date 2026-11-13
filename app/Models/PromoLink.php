<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PromoLink extends Model
{
    protected $fillable = ['seller_id', 'product_id', 'code', 'label', 'clicks', 'orders', 'revenue'];

    protected $casts = ['revenue' => 'decimal:2'];

    protected static function booted(): void
    {
        static::creating(function (PromoLink $link) {
            $link->code ??= strtoupper(Str::random(8));
        });
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Links made by Social Studio report as "social" in Marketing insights. */
    public function channel(): string
    {
        if (str_starts_with((string) $this->label, 'Link in bio')) {
            return 'bio';
        }

        return SocialPostTarget::where('promo_link_id', $this->id)->exists() ? 'social' : 'promo_link';
    }

    public function destination(): string
    {
        return $this->product
            ? route('products.show', $this->product->slug)
            : route('sellers.show', $this->seller->sellerProfile);
    }
}
