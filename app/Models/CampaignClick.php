<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One click on one link in one campaign email. Bot-like clicks are kept but left out of stats. */
class CampaignClick extends Model
{
    public $timestamps = false;

    protected $fillable = ['marketing_campaign_id', 'campaign_recipient_id', 'link_key', 'url', 'is_bot', 'clicked_at'];

    protected $casts = ['is_bot' => 'boolean', 'clicked_at' => 'datetime'];
}
