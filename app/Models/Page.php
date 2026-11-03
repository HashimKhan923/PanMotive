<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    protected $fillable = ['title', 'slug', 'body', 'meta_description', 'is_published', 'show_in_footer', 'updated_by'];

    protected $casts = ['is_published' => 'boolean', 'show_in_footer' => 'boolean'];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
