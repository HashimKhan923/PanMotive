<?php

namespace App\Notifications;

use App\Models\OrderItem;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SellerPayoutReleased extends Notification
{
    use Queueable;

    public function __construct(public OrderItem $orderItem) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'payout',
            'title' => 'Payout released: $'.number_format($this->orderItem->seller_payout_amount, 2),
            'body' => "Order {$this->orderItem->order->order_number} was confirmed delivered.",
            'url' => route('seller.payouts.index'),
            'order_item_id' => $this->orderItem->id,
        ];
    }
}
