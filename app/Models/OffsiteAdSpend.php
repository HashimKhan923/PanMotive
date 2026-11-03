<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OffsiteAdSpend extends Model
{
    protected $table = 'offsite_ad_spend';

    protected $fillable = ['month', 'channel', 'amount', 'note'];

    protected $casts = ['month' => 'date', 'amount' => 'decimal:2'];
}
