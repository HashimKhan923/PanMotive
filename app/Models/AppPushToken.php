<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A phone with the shopping app that gets push notifications (an Expo push token). */
class AppPushToken extends Model
{
    protected $fillable = ['user_id', 'token', 'platform', 'device_id', 'last_seen_at'];

    protected $casts = ['last_seen_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
