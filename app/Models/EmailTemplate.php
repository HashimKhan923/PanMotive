<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One email's edited wording (see App\Support\EmailTemplates). */
class EmailTemplate extends Model
{
    protected $fillable = ['key', 'subject', 'preheader', 'greeting', 'intro', 'button', 'outro', 'updated_by'];

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
