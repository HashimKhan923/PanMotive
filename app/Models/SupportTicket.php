<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SupportTicket extends Model
{
    public const CATEGORIES = [
        'orders' => 'Orders & fulfilment',
        'payouts' => 'Payouts & fees',
        'listings' => 'Listings & catalog',
        'returns' => 'Returns, claims & disputes',
        'account' => 'Account, verification & health',
        'technical' => 'Technical problem / bug',
        'other' => 'Something else',
    ];

    public const STATUSES = [
        'open' => 'Waiting on support',
        'answered' => 'Waiting on you',
        'resolved' => 'Resolved',
    ];

    protected $fillable = [
        'ticket_number', 'seller_id', 'opened_by', 'category', 'subject', 'order_item_id',
        'status', 'priority', 'seller_unread', 'admin_unread', 'last_reply_at', 'resolved_at',
    ];

    protected $casts = [
        'seller_unread' => 'boolean',
        'admin_unread' => 'boolean',
        'last_reply_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (SupportTicket $t) {
            $t->ticket_number ??= 'ST-'.strtoupper(Str::random(8));
        });
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->oldest();
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }
}
