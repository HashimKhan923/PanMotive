<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Which Panmotive product a Shopify product is kept in step with. */
class ShopifyProductLink extends Model
{
    protected $fillable = ['shopify_connection_id', 'shopify_product_id', 'product_id', 'payload_hash', 'synced_at'];

    protected $casts = ['synced_at' => 'datetime'];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ShopifyConnection::class, 'shopify_connection_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
