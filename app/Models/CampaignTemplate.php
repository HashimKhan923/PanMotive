<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A seller's saved email design, reusable for future campaigns. */
class CampaignTemplate extends Model
{
    protected $fillable = ['seller_id', 'name', 'design'];

    protected $casts = ['design' => 'array'];
}
