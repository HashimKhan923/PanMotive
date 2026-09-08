<?php

namespace App\Console\Commands;

use App\Models\Cart;
use App\Notifications\AbandonedCartReminder;
use Illuminate\Console\Command;

class SendAbandonedCartReminders extends Command
{
    protected $signature = 'carts:send-abandoned-reminders';

    protected $description = 'Email users who left items in their cart without checking out';

    public function handle(): void
    {
        $hours = config('marketplace.abandoned_cart_hours');

        $carts = Cart::whereNotNull('user_id')
            ->whereHas('items')
            ->where('updated_at', '<=', now()->subHours($hours))
            ->whereNull('abandoned_reminder_sent_at')
            ->with('user', 'items.product')
            ->get();

        foreach ($carts as $cart) {
            $cart->user?->notify(new AbandonedCartReminder($cart));
            $cart->update(['abandoned_reminder_sent_at' => now()]);
        }

        $this->info("Sent {$carts->count()} abandoned cart reminders.");
    }
}
