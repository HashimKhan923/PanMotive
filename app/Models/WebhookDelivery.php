<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One attempt to deliver a webhook — kept so sellers can see what we sent and what came back. */
class WebhookDelivery extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['webhook_endpoint_id', 'event', 'status', 'success', 'duration_ms', 'error'];

    protected $casts = ['success' => 'boolean', 'created_at' => 'datetime'];
}
