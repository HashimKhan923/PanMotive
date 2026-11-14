<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** A seller's free-hand social image: the editable canvas (JSON) and its exported PNG. */
class SocialDesign extends Model
{
    protected $fillable = ['seller_id', 'name', 'size', 'canvas', 'image_path'];

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }
}
