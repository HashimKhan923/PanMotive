<?php

namespace App\Services\Marketing;

use App\Mail\CampaignMail;
use App\Models\AutomationSend;
use App\Models\MarketingAutomation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class MarketingAutomationService
{
    public function __construct(private MarketingConsent $consent) {}

    /** Run active automations — every store's (scheduler) or one store's ("Run now"). Returns messages sent. */
    public function run(?int $sellerId = null): int
    {
        $sent = 0;

        MarketingAutomation::where('is_active', true)
            ->when($sellerId, fn ($q) => $q->where('seller_id', $sellerId))
            ->whereDoesntHave('seller.sellerProfile', fn ($q) => $q->whereIn('status', ['suspended', 'rejected']))
            ->whereHas('seller', fn ($q) => $q->where('is_active', true)->orWhereNull('is_active'))
            ->with('seller.sellerProfile')
            ->get()
            ->each(function (MarketingAutomation $automation) use (&$sent) {
                foreach ($this->candidates($automation) as [$user, $dedupeKey]) {
                    if ($this->dispatch($automation, $user, $dedupeKey)) {
                        $sent++;
                    }
                }
                $automation->update(['last_run_at' => now()]);
                $this->refreshStats($automation);
            });

        return $sent;
    }

    /**
     * Resolve [User, dedupeKey] pairs eligible for this automation right now.
     *
     * @return iterable<array{0:User,1:string}>
     */
    public function candidates(MarketingAutomation $automation): iterable
    {
        $sellerId = $automation->seller_id;
        $cutoff = now()->subHours(max(1, $automation->delay_hours));

        return match ($automation->trigger) {
            'abandoned_cart' => $this->abandonedCarts($sellerId, $cutoff),
            'post_purchase' => $this->postPurchase($sellerId, $cutoff),
            'win_back' => $this->winBack($automation, $sellerId),
            'new_follower' => $this->newFollowers($sellerId, $cutoff),
            'price_drop' => $this->priceDrops($sellerId),
            default => [],
        };
    }

    private function abandonedCarts(int $sellerId, Carbon $cutoff): iterable
    {
        $rows = DB::table('cart_items')
            ->join('carts', 'carts.id', '=', 'cart_items.cart_id')
            ->join('products', 'products.id', '=', 'cart_items.product_id')
            ->join('users', 'users.id', '=', 'carts.user_id')
            ->where('products.seller_id', $sellerId)
            ->whereNotNull('carts.user_id')
            ->whereNotNull('users.email')
            ->where('cart_items.updated_at', '<', $cutoff)
            ->where('cart_items.updated_at', '>', now()->subDays(14))
            ->select('carts.id as cart_id', 'carts.user_id')
            ->distinct()
            ->get();

        foreach ($rows as $row) {
            // Skip if the buyer has ordered from this seller since the cart went cold.
            $ordered = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('order_items.seller_id', $sellerId)
                ->where('orders.buyer_id', $row->user_id)
                ->where('orders.created_at', '>=', $cutoff)
                ->exists();
            if ($ordered) {
                continue;
            }
            if ($user = User::find($row->user_id)) {
                yield [$user, "cart:{$row->cart_id}"];
            }
        }
    }

    private function postPurchase(int $sellerId, Carbon $cutoff): iterable
    {
        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.seller_id', $sellerId)
            ->whereNotIn('order_items.status', ['placed', 'cancelled', 'refunded'])
            ->whereNotNull('orders.buyer_id')
            ->where('orders.created_at', '<', $cutoff)
            ->where('orders.created_at', '>', now()->subDays(30))
            ->select('orders.id as order_id', 'orders.buyer_id')
            ->distinct()
            ->get();

        foreach ($rows as $row) {
            if ($user = User::find($row->buyer_id)) {
                yield [$user, "order:{$row->order_id}"];
            }
        }
    }

    private function winBack(MarketingAutomation $automation, int $sellerId): iterable
    {
        $days = (int) ($automation->settings['lapsed_days'] ?? 90);
        $lapsedBefore = now()->subDays($days);

        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.seller_id', $sellerId)
            ->whereNotIn('order_items.status', ['placed', 'cancelled', 'refunded'])
            ->whereNotNull('orders.buyer_id')
            ->groupBy('orders.buyer_id')
            ->select('orders.buyer_id', DB::raw('MAX(orders.created_at) as last_order'))
            ->havingRaw('MAX(orders.created_at) < ?', [$lapsedBefore])
            ->get();

        foreach ($rows as $row) {
            // One win-back per lapse window (dedupe key rounds to the month).
            $window = Carbon::parse($row->last_order)->format('Y-m');
            if ($user = User::find($row->buyer_id)) {
                yield [$user, "winback:{$row->buyer_id}:{$window}"];
            }
        }
    }

    private function newFollowers(int $sellerId, Carbon $cutoff): iterable
    {
        $rows = DB::table('seller_followers')
            ->join('users', 'users.id', '=', 'seller_followers.user_id')
            ->where('seller_followers.seller_id', $sellerId)
            ->whereNotNull('users.email')
            ->where('seller_followers.created_at', '<', $cutoff)
            ->where('seller_followers.created_at', '>', now()->subDays(30))
            ->select('seller_followers.id', 'seller_followers.user_id')
            ->get();

        foreach ($rows as $row) {
            if ($user = User::find($row->user_id)) {
                yield [$user, "follow:{$row->id}"];
            }
        }
    }

    private function priceDrops(int $sellerId): iterable
    {
        $rows = DB::table('wishlist_items')
            ->join('wishlists', 'wishlists.id', '=', 'wishlist_items.wishlist_id')
            ->join('products', 'products.id', '=', 'wishlist_items.product_id')
            ->join('users', 'users.id', '=', 'wishlists.user_id')
            ->where('products.seller_id', $sellerId)
            ->whereNotNull('wishlists.user_id')
            ->whereNotNull('users.email')
            ->whereNotNull('products.sale_price')
            ->whereNotNull('products.sale_starts_at')
            ->where('products.sale_starts_at', '<=', now())
            ->whereColumn('wishlist_items.created_at', '<', 'products.sale_starts_at')
            ->where(fn ($q) => $q->whereNull('products.deal_ends_at')->orWhere('products.deal_ends_at', '>', now()))
            ->select('wishlists.user_id', 'products.id as product_id', 'products.sale_starts_at')
            ->get();

        foreach ($rows as $row) {
            $window = Carbon::parse($row->sale_starts_at)->timestamp;
            if ($user = User::find($row->user_id)) {
                yield [$user, "wishlist:{$row->product_id}:{$window}"];
            }
        }
    }

    /** Send one automation email if it hasn't already gone to this dedupe key. */
    public function dispatch(MarketingAutomation $automation, User $user, string $dedupeKey): bool
    {
        if (! $this->consent->canEmail($user, $automation->seller_id)) {
            return false;
        }

        $send = AutomationSend::firstOrNew([
            'marketing_automation_id' => $automation->id,
            'dedupe_key' => $dedupeKey,
        ]);
        if ($send->exists) {
            return false;
        }

        // Frequency cap: one marketing email per store per buyer per day. Not recorded, so the
        // automation tries again on a later run once the window has passed.
        if ($this->consent->recentlyEmailed($user->id, $automation->seller_id)) {
            return false;
        }
        $send->user_id = $user->id;
        $send->token = Str::random(40);
        $send->save();

        $store = $automation->seller->sellerProfile?->business_name ?? 'the store';
        $rendered = $this->render($automation, $user, $store, $send->token);

        try {
            Mail::to($user->email)->send(new CampaignMail(
                $rendered['subject'], $rendered['preview'], $rendered['body'],
                $this->consent->unsubscribeUrl($user, $automation->seller_id), $store,
            ));
        } catch (\Throwable $e) {
            $send->delete();

            return false;
        }

        return true;
    }

    /** @return array{subject:string, preview:string, body:string} */
    public function render(MarketingAutomation $automation, User $user, string $store, string $token): array
    {
        $shopUrl = route('am.click', $token);
        $first = Str::before($user->name ?: 'there', ' ');

        $swap = fn (string $s) => str_replace(
            ['{{name}}', '{{store}}', '{{shop_url}}'],
            [$first, $store, $shopUrl],
            $s
        );

        $body = nl2br(e($swap($automation->body)));
        $body = str_replace(e($shopUrl), '<a href="'.$shopUrl.'" style="color:#C8161D;font-weight:600">'.$shopUrl.'</a>', $body);
        $body .= '<img src="'.route('am.open', $token).'" width="1" height="1" alt="" style="display:none">';

        return [
            'subject' => $swap($automation->subject ?: "News from {$store}"),
            'preview' => $swap(Str::limit(strip_tags($automation->body), 100, '')),
            'body' => $body,
        ];
    }

    public function refreshStats(MarketingAutomation $automation): void
    {
        $automation->update(['stats' => [
            'sent' => (int) $automation->sends()->count(),
            'opened' => (int) $automation->sends()->whereNotNull('opened_at')->count(),
            'clicked' => (int) $automation->sends()->whereNotNull('clicked_at')->count(),
            'orders' => (int) $automation->sends()->whereNotNull('converted_order_id')->count(),
            'revenue' => (float) $automation->sends()->sum('conversion_value'),
        ]]);
    }
}
