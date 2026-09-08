<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LoyaltyTierUpgraded extends Notification
{
    use Queueable;

    public function __construct(public string $tier) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['type' => 'loyalty_tier_upgraded', 'tier' => $this->tier];
    }
}
