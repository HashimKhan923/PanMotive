<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Vehicle extends Model
{
    protected $fillable = ['vehicle_make_id', 'vehicle_model_id', 'vehicle_engine_id', 'year', 'trim'];

    public function make(): BelongsTo
    {
        return $this->belongsTo(VehicleMake::class, 'vehicle_make_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'vehicle_model_id');
    }

    public function engine(): BelongsTo
    {
        return $this->belongsTo(VehicleEngine::class, 'vehicle_engine_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_fitments');
    }

    public function label(): string
    {
        return trim("{$this->year} {$this->make->name} {$this->model->name} ".($this->trim ?? ''));
    }
}
