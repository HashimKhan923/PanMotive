<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    protected $fillable = ['seller_id', 'name', 'token_hash', 'preview', 'abilities', 'last_used_at', 'revoked_at'];

    protected $casts = ['abilities' => 'array', 'last_used_at' => 'datetime', 'revoked_at' => 'datetime'];

    /** @return array{model: ApiToken, plain: string} */
    public static function issue(User $seller, string $name, array $abilities): array
    {
        $plain = 'pm_live_'.Str::random(40);

        $model = static::create([
            'seller_id' => $seller->id,
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'preview' => substr($plain, 0, 12),
            'abilities' => $abilities,
        ]);

        return ['model' => $model, 'plain' => $plain];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function can(string $ability): bool
    {
        return in_array($ability, $this->abilities ?? [], true);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
