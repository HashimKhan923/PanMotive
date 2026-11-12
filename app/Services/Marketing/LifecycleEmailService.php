<?php

namespace App\Services\Marketing;

use App\Enums\OrderStatus;
use App\Models\MarketingUnsubscribe;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Notifications\BrowseReminder;
use App\Notifications\DidItFit;
use App\Notifications\GarageTip;
use App\Notifications\WinBack;
use App\Support\EmailPreferences;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Panmotive's own automatic emails, run hourly. Each is sent at most once per window, only
 * to people who haven't switched it off, and never to staff accounts. The two sales-y ones
 * (browse reminder, win-back) also respect "unsubscribe from all marketing".
 */
class LifecycleEmailService
{
    private const STAFF_ROLES = ['super_admin', 'support_agent', 'finance', 'catalog_manager'];

    /** @return array<string, int> how many of each went out */
    public function run(): array
    {
        return [
            'browse' => $this->browseReminders(),
            'garage_tip' => $this->garageTips(),
            'did_it_fit' => $this->fitChecks(),
            'winback' => $this->winBacks(),
        ];
    }

    /** Viewed in the last 2–48 hours, didn't buy or add to cart. At most one every 7 days. */
    public function browseReminders(): int
    {
        $keys = DB::table('product_views')->where('viewer_key', 'like', 'u%')
            ->whereBetween('viewed_at', [now()->subHours(48), now()->subHours(2)])
            ->distinct()->pluck('viewer_key');
        $sent = 0;

        foreach ($keys as $key) {
            $user = $this->people()->whereKey((int) substr($key, 1))
                ->where(fn ($q) => $q->whereNull('browse_reminder_sent_at')->orWhere('browse_reminder_sent_at', '<', now()->subDays(7)))->first();
            if (! $user || ! $this->marketingOk($user, 'browse_reminders')) {
                continue;
            }

            $viewed = DB::table('product_views')->where('viewer_key', $key)
                ->whereBetween('viewed_at', [now()->subHours(48), now()->subHours(2)])
                ->orderByDesc('viewed_at')->pluck('product_id')->unique();
            $bought = OrderItem::whereHas('order', fn ($q) => $q->where('buyer_id', $user->id))
                ->where('created_at', '>=', now()->subDays(30))->pluck('product_id');
            $inCart = DB::table('cart_items')->join('carts', 'carts.id', '=', 'cart_items.cart_id')
                ->where('carts.user_id', $user->id)->pluck('cart_items.product_id');

            $products = Product::listed()->whereIn('id', $viewed->diff($bought)->diff($inCart)->take(10))
                ->where('quantity', '>', 0)->with('seller.sellerProfile', 'media')->get()
                ->sortBy(fn ($p) => $viewed->search($p->id))->take(3)->values();
            if ($products->isEmpty()) {
                continue;
            }

            $user->forceFill(['browse_reminder_sent_at' => now()])->save();
            $this->send($user, new BrowseReminder($products)) && $sent++;
        }

        return $sent;
    }

    /** Joined 2–7 days ago and still no vehicle in the garage: one tip, ever. */
    public function garageTips(): int
    {
        $sent = 0;
        $this->people()->whereNull('active_vehicle')->whereNull('garage_tip_sent_at')
            ->whereBetween('created_at', [now()->subDays(7), now()->subDays(2)])
            ->chunkById(200, function ($users) use (&$sent) {
                foreach ($users as $user) {
                    if (! EmailPreferences::wants($user, 'tips')) {
                        continue;
                    }
                    $user->forceFill(['garage_tip_sent_at' => now()])->save();
                    $this->send($user, new GarageTip) && $sent++;
                }
            });

        return $sent;
    }

    /** 3–10 days after delivery, once per order: did it fit? (and a review request). */
    public function fitChecks(): int
    {
        $sent = 0;
        Order::whereNotNull('buyer_id')->whereNull('fit_check_sent_at')
            ->whereHas('items', fn ($q) => $q->whereBetween('delivered_at', [now()->subDays(10), now()->subDays(3)]))
            ->with('buyer')
            ->chunkById(200, function ($orders) use (&$sent) {
                foreach ($orders as $order) {
                    $order->forceFill(['fit_check_sent_at' => now()])->save();
                    $buyer = $order->buyer;
                    if (! $buyer || $buyer->is_active === false || ! EmailPreferences::wants($buyer, 'fit_checks')) {
                        continue;
                    }

                    $reviewed = DB::table('reviews')->where('user_id', $buyer->id)->pluck('order_item_id')->filter();
                    $items = $order->items()
                        ->whereIn('status', [OrderStatus::Delivered, OrderStatus::PayoutReleased])
                        ->whereNotIn('id', $reviewed)->get();
                    if ($items->isEmpty()) {
                        continue;
                    }
                    $this->send($buyer, new DidItFit($order, $items)) && $sent++;
                }
            });

        return $sent;
    }

    /** Last order 90+ days ago and nothing since: parts for their vehicle. At most twice a year. */
    public function winBacks(): int
    {
        $sent = 0;
        $active = [OrderStatus::Placed->value, OrderStatus::Cancelled->value];

        $this->people()
            ->whereHas('orders', fn ($q) => $q->whereNotIn('status', $active))
            ->whereDoesntHave('orders', fn ($q) => $q->where('created_at', '>=', now()->subDays(90)))
            ->where(fn ($q) => $q->whereNull('winback_sent_at')->orWhere('winback_sent_at', '<', now()->subDays(180)))
            ->chunkById(200, function ($users) use (&$sent) {
                foreach ($users as $user) {
                    if (! $this->marketingOk($user, 'winback')) {
                        continue;
                    }
                    $vehicle = $user->active_vehicle;
                    $products = Product::listed()->where('quantity', '>', 0)
                        ->when($vehicle['id'] ?? null, fn ($q, $id) => $q->whereHas('fitments', fn ($f) => $f->where('vehicles.id', $id)))
                        ->with('seller.sellerProfile', 'media')->latest('id')->take(4)->get();
                    if ($products->isEmpty() && ($vehicle['id'] ?? null)) {
                        continue; // nothing listed for their vehicle yet: better silent than off-topic
                    }

                    $user->forceFill(['winback_sent_at' => now()])->save();
                    $url = ($vehicle['id'] ?? null) ? route('products.index', ['vehicleId' => $vehicle['id']]) : route('products.index');
                    $this->send($user, new WinBack($products, $vehicle['label'] ?? null, $url)) && $sent++;
                }
            });

        return $sent;
    }

    /** Active, real customers with an email address (not Panmotive staff). */
    private function people(): Builder
    {
        return User::query()->whereNotNull('email')->where('is_active', true)
            ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', self::STAFF_ROLES));
    }

    private function marketingOk(User $user, string $category): bool
    {
        return EmailPreferences::wants($user, $category)
            && ! MarketingUnsubscribe::where('user_id', $user->id)->whereNull('seller_id')->exists();
    }

    private function send(User $user, \Illuminate\Notifications\Notification $notification): bool
    {
        try {
            $user->notify($notification);

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }
}
