<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ReturnRequest extends Model
{
    protected $fillable = [
        'rma_number', 'order_item_id', 'user_id', 'reason', 'detail',
        'status', 'respond_by', 'escalated_at', 'escalated_by',
        'resolution_note', 'resolved_by', 'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'respond_by' => 'datetime',
        'escalated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (ReturnRequest $r) {
            $r->rma_number ??= 'RMA-'.strtoupper(Str::random(8));
            $r->respond_by ??= now()->addHours(48);
        });

        static::created(function (ReturnRequest $r) {
            $r->events()->create([
                'actor_id' => $r->user_id,
                'actor_role' => 'buyer',
                'event' => 'requested',
                'note' => $r->reason,
            ]);
        });
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(DisputeEvent::class)->oldest();
    }

    public function isOverdue(): bool
    {
        return $this->status === 'requested'
            && ! $this->escalated_at
            && $this->respond_by
            && $this->respond_by->isPast();
    }

    public function hoursToRespond(): ?int
    {
        return $this->respond_by && $this->status === 'requested'
            ? (int) ceil(now()->diffInMinutes($this->respond_by, false) / 60)
            : null;
    }

    public function log(string $event, string $role, ?int $actorId = null, ?string $note = null): void
    {
        $this->events()->create([
            'actor_id' => $actorId,
            'actor_role' => $role,
            'event' => $event,
            'note' => $note,
        ]);
    }
}
