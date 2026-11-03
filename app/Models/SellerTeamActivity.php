<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerTeamActivity extends Model
{
    protected $table = 'seller_team_activity';

    protected $fillable = ['owner_id', 'actor_id', 'actor_name', 'action', 'subject', 'meta'];

    protected $casts = ['meta' => 'array'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
