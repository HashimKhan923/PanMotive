<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleEngine extends Model
{
    protected $fillable = ['name', 'fuel_type'];

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }
}
