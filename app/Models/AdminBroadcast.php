<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An email from Pan Motive itself to its buyers and/or sellers. */
class AdminBroadcast extends Model
{
    public const AUDIENCES = ['buyers' => 'All buyers', 'sellers' => 'All sellers', 'everyone' => 'Everyone'];

    private const NOT_BUYERS = ['seller', 'super_admin', 'support_agent', 'finance', 'catalog_manager'];

    protected $fillable = ['subject', 'preview', 'body', 'audience', 'kind', 'status', 'recipients', 'sent_count', 'failed_count', 'created_by', 'sent_at'];

    protected $casts = ['sent_at' => 'datetime'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Suspended accounts never get mail, and marketing skips anyone who unsubscribed from Pan Motive email. */
    public static function audienceQuery(string $audience, string $kind): Builder
    {
        return User::query()
            ->where(fn ($q) => $q->where('is_active', true)->orWhereNull('is_active'))
            ->when($audience === 'buyers', fn ($q) => $q->whereDoesntHave('roles', fn ($r) => $r->whereIn('name', self::NOT_BUYERS)))
            ->when($audience === 'sellers', fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', 'seller')))
            ->when($audience === 'everyone', fn ($q) => $q->whereDoesntHave('roles', fn ($r) => $r->whereIn('name', ['super_admin', 'support_agent', 'finance', 'catalog_manager'])))
            ->when($kind === 'marketing', fn ($q) => $q->whereNotExists(fn ($s) => $s->selectRaw('1')->from('marketing_unsubscribes')
                ->whereColumn('marketing_unsubscribes.user_id', 'users.id')->whereNull('marketing_unsubscribes.seller_id')));
    }
}
