<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = [
        'buyer_id', 'seller_id', 'product_id', 'order_id', 'subject',
        'last_message_at', 'awaiting_reply_since', 'buyer_unread', 'seller_unread', 'closed',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'awaiting_reply_since' => 'datetime',
        'away_reply_sent_at' => 'datetime',
        'closed' => 'boolean',
    ];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->oldest()->orderBy('id');
    }

    public function latestMessage(): HasMany
    {
        return $this->hasMany(Message::class)->latest()->orderByDesc('id')->limit(1);
    }

    /** Role of the given user in this thread: 'buyer' | 'seller' | null. */
    public function roleOf(?User $user): ?string
    {
        return match ($user?->id) {
            $this->buyer_id => 'buyer',
            $this->seller_id => 'seller',
            default => null,
        };
    }

    /** @param array<int, array{path:string, name:string}> $attachments */
    public function post(User $sender, string $body, array $attachments = []): Message
    {
        $role = $this->roleOf($sender);

        $message = $this->messages()->create([
            'sender_id' => $sender->id,
            'body' => $body,
            'attachments' => $attachments ?: null,
        ]);

        $buyerUnread = (int) $this->buyer_unread;
        $sellerUnread = (int) $this->seller_unread;

        $this->forceFill([
            'last_message_at' => $message->created_at,
            'buyer_unread' => $role === 'seller' ? $buyerUnread + 1 : $buyerUnread,
            'seller_unread' => $role === 'buyer' ? $sellerUnread + 1 : $sellerUnread,
            // The clock starts at the buyer's first unanswered message and stops when the seller replies.
            'awaiting_reply_since' => match ($role) {
                'buyer' => $this->awaiting_reply_since ?? $message->created_at,
                'seller' => null,
                default => $this->awaiting_reply_since,
            },
            'closed' => false,
        ])->save();

        return $message;
    }

    /** Waiting on the seller for longer than the response target. */
    public function isOverdue(): bool
    {
        return $this->awaiting_reply_since !== null
            && $this->awaiting_reply_since->lt(now()->subHours(config('marketplace.messages.response_target_hours', 24)));
    }

    /** Flag a thread to come back to — shows as unread again in the seller's inbox. */
    public function markUnreadForSeller(): void
    {
        $this->forceFill(['seller_unread' => max(1, (int) $this->seller_unread)])->save();
    }

    public function markReadFor(string $role): void
    {
        $column = $role === 'seller' ? 'seller_unread' : 'buyer_unread';

        if ($this->$column > 0) {
            $this->forceFill([$column => 0])->save();
        }

        $this->messages()
            ->whereNull('read_at')
            ->where('sender_id', '!=', $role === 'seller' ? $this->seller_id : $this->buyer_id)
            ->update(['read_at' => now()]);
    }
}
