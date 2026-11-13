<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** An image a seller uploaded for their emails (kept so admins can review or remove it). */
class CampaignImage extends Model
{
    protected $fillable = ['seller_id', 'path', 'size'];

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }
}
