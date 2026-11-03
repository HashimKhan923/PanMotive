<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingAutomation extends Model
{
    protected $fillable = [
        'seller_id', 'trigger', 'is_active', 'channel', 'delay_hours',
        'subject', 'body', 'settings', 'stats', 'last_run_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
        'stats' => 'array',
        'last_run_at' => 'datetime',
    ];

    public const TRIGGERS = [
        'abandoned_cart' => 'Abandoned cart',
        'post_purchase' => 'Post-purchase thank you',
        'win_back' => 'Win back lapsed customers',
        'new_follower' => 'Welcome new followers',
        'price_drop' => 'Wishlist price drop',
    ];

    public const DEFAULT_COPY = [
        'abandoned_cart' => [
            'delay_hours' => 4,
            'subject' => 'You left something in your cart',
            'body' => "Hi {{name}},\n\nYour cart at {{store}} is still waiting. Complete your order before these parts sell out.\n\n{{shop_url}}",
        ],
        'post_purchase' => [
            'delay_hours' => 24,
            'subject' => 'Thanks for your order',
            'body' => "Hi {{name}},\n\nThank you for shopping with {{store}}. If everything fits and works, a quick review helps other buyers a lot.\n\n{{shop_url}}",
        ],
        'win_back' => [
            'delay_hours' => 1,
            'subject' => "We've missed you",
            'body' => "Hi {{name}},\n\nIt's been a while. Come see what's new at {{store}}.\n\n{{shop_url}}",
        ],
        'new_follower' => [
            'delay_hours' => 1,
            'subject' => 'Thanks for following',
            'body' => "Hi {{name}},\n\nThanks for following {{store}}. You'll be first to hear about new stock and sales.\n\n{{shop_url}}",
        ],
        'price_drop' => [
            'delay_hours' => 1,
            'subject' => 'A part on your wishlist just dropped in price',
            'body' => "Hi {{name}},\n\nGood news — an item you saved from {{store}} is now on sale.\n\n{{shop_url}}",
        ],
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function sends(): HasMany
    {
        return $this->hasMany(AutomationSend::class);
    }

    public function label(): string
    {
        return self::TRIGGERS[$this->trigger] ?? $this->trigger;
    }

    public function stat(string $key): int
    {
        return (int) ($this->stats[$key] ?? 0);
    }
}
