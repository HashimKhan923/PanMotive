<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryLocation extends Model
{
    protected $fillable = ['seller_id', 'name', 'code', 'is_default', 'address'];

    protected $casts = ['is_default' => 'boolean', 'address' => 'array'];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function stock(): HasMany
    {
        return $this->hasMany(ProductStock::class);
    }
}
