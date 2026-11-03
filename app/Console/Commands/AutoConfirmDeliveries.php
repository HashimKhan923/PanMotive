<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Jobs\ReleaseSellerPayout;
use App\Models\OrderItem;
use Illuminate\Console\Command;

class AutoConfirmDeliveries extends Command
{
    protected $signature = 'orders:auto-confirm-deliveries';

    protected $description = 'Auto-confirm delivery and release payout for shipped orders past the grace period';

    public function handle(): void
    {
        $days = config('marketplace.auto_confirm_delivery_days');

        // "Out for delivery" counts too — otherwise a carrier scan that never turns into
        // "delivered" would leave the seller's money stuck forever.
        $items = OrderItem::whereIn('status', [OrderStatus::Shipped, OrderStatus::OutForDelivery])
            ->where('shipped_at', '<=', now()->subDays($days))
            ->get();

        foreach ($items as $item) {
            $item->update([
                'status' => OrderStatus::Delivered,
                'delivered_at' => now(),
            ]);

            app(\App\Services\Integrations\WebhookDispatcher::class)->orderEvent('order.delivered', [$item->fresh('order')]);
            ReleaseSellerPayout::dispatch($item->id);
        }

        $this->info("Auto-confirmed {$items->count()} deliveries.");
    }
}
