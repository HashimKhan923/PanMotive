<?php

namespace App\Services\Marketing;

use App\Models\MarketingUnsubscribe;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Who a seller may send marketing to. Every campaign and automation goes through here so
 * opt-outs (CAN-SPAM), blocked buyers and the per-buyer frequency cap are honoured in one place.
 */
class MarketingConsent
{
    /** A buyer gets at most one marketing email per store in this many hours. */
    public static function capHours(): int
    {
        return (int) config('marketplace.marketing.frequency_cap_hours', 24);
    }

    public function canEmail(User $user, int $sellerId): bool
    {
        return $user->email
            && $user->is_active !== false
            && ! MarketingUnsubscribe::where('user_id', $user->id)
                ->where(fn ($q) => $q->whereNull('seller_id')->orWhere('seller_id', $sellerId))->exists()
            && ! DB::table('seller_blocked_buyers')->where('seller_id', $sellerId)->where('buyer_id', $user->id)->exists();
    }

    public function canText(User $user, int $sellerId): bool
    {
        return $user->sms_opt_in_at !== null && ! empty($user->phone) && $this->canEmail($user, $sellerId);
    }

    /** Drop opted-out and blocked buyers from an audience query (on the users table). */
    public function scopeAudience(Builder $users, int $sellerId): Builder
    {
        return $users
            ->where(fn ($q) => $q->where('users.is_active', true)->orWhereNull('users.is_active'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('marketing_unsubscribes')
                ->whereColumn('marketing_unsubscribes.user_id', 'users.id')
                ->where(fn ($w) => $w->whereNull('marketing_unsubscribes.seller_id')->orWhere('marketing_unsubscribes.seller_id', $sellerId)))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('seller_blocked_buyers')
                ->whereColumn('seller_blocked_buyers.buyer_id', 'users.id')
                ->where('seller_blocked_buyers.seller_id', $sellerId));
    }

    /** Has this store sent the buyer a campaign or automation email recently? */
    public function recentlyEmailed(int $userId, int $sellerId, ?int $hours = null): bool
    {
        $since = now()->subHours($hours ?? self::capHours());

        return DB::table('campaign_recipients')
            ->join('marketing_campaigns', 'marketing_campaigns.id', '=', 'campaign_recipients.marketing_campaign_id')
            ->where('marketing_campaigns.seller_id', $sellerId)
            ->where('campaign_recipients.user_id', $userId)
            ->where('campaign_recipients.sent_at', '>=', $since)->exists()
            || DB::table('automation_sends')
                ->join('marketing_automations', 'marketing_automations.id', '=', 'automation_sends.marketing_automation_id')
                ->where('marketing_automations.seller_id', $sellerId)
                ->where('automation_sends.user_id', $userId)
                ->where('automation_sends.created_at', '>=', $since)->exists();
    }

    /** Signed link that works without logging in (from any email client). */
    public function unsubscribeUrl(User $user, ?int $sellerId): string
    {
        return URL::signedRoute('marketing.unsubscribe', array_filter(['user' => $user->id, 'seller' => $sellerId]));
    }

    public function unsubscribe(User $user, ?int $sellerId, string $source = 'link'): void
    {
        MarketingUnsubscribe::firstOrCreate(['user_id' => $user->id, 'seller_id' => $sellerId], ['source' => $source]);
    }

    public function resubscribe(User $user, ?int $sellerId): void
    {
        MarketingUnsubscribe::where('user_id', $user->id)
            ->when($sellerId, fn ($q) => $q->where('seller_id', $sellerId), fn ($q) => $q->whereNull('seller_id'))
            ->delete();
    }
}
