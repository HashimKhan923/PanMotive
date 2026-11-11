<?php

namespace App\Notifications;

use App\Models\OrderItem;
use App\Notifications\Concerns\QueuesEmail;
use App\Notifications\Messages\PanmotiveMail;
use App\Support\EmailPreferences;
use App\Support\OrderMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class SellerPayoutReleased extends Notification implements ShouldQueue
{
    use Queueable, QueuesEmail;

    public function __construct(public OrderItem $orderItem) {}

    public function via(object $notifiable): array
    {
        return ($notifiable->email ?? null) && EmailPreferences::wants($notifiable, 'payouts') ? ['mail', 'database'] : ['database'];
    }

    public function toMail(object $notifiable): PanmotiveMail
    {
        $item = $this->orderItem->loadMissing('order', 'product.media', 'variant.media');
        $amount = PanmotiveMail::money($item->seller_payout_amount);

        return PanmotiveMail::to($notifiable)
            ->forSeller()
            ->subject("You’ve been paid {$amount} for order {$item->order->order_number}")
            ->preheader('The buyer received their order, so your money is on its way.')
            ->line('The buyer received their order, so we’ve released your money.')
            ->items(OrderMail::items([$item], withStore: false))
            ->totals([
                ['Sale', PanmotiveMail::money($item->line_total)],
                ['Panmotive fee', '−'.PanmotiveMail::money($item->platform_fee_amount)],
                ['Paid to you', $amount, true],
            ])
            ->line('It usually reaches your bank in 2–3 business days, depending on your bank.')
            ->action('See your payouts', route('seller.payouts.index'))
            ->optional($notifiable, 'payouts');
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
