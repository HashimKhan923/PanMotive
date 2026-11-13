<?php

namespace App\Notifications;

use App\Models\Order;
use App\Notifications\Concerns\QueuesEmail;
use App\Notifications\Messages\PanmotiveMail;
use App\Support\OrderMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * A paid order is waiting for the seller: in-app, by email (with only their own lines, what
 * they'll earn and the ship-by date) and by Web Push when they've opted in.
 */
class NewOrderReceived extends Notification implements ShouldQueue
{
    use Queueable, QueuesEmail;

    public function __construct(public Order $order) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable->email ?? null) {
            $channels[] = 'mail';
        }

        if (method_exists($notifiable, 'pushSubscriptions') && $notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): PanmotiveMail
    {
        $items = $this->order->items()->where('seller_id', $notifiable->getKey())
            ->with('product.media', 'variant.media', 'order')->get();
        $earn = (float) $items->sum('seller_payout_amount');
        $shipBy = $items->map->handlingDeadline()->filter()->min();
        $ship = $this->order->shipping_address ?? [];
        $first = $items->first();

        return PanmotiveMail::to($notifiable)
            ->forSeller()
            ->useWords('new_order', [
                'order_number' => $this->order->order_number, 'earnings' => PanmotiveMail::money($earn),
                'ship_by' => $shipBy?->format('D, M j') ?? 'soon',
                'ship_to' => trim(($ship['city'] ?? '').(! empty($ship['state']) ? ', '.$ship['state'] : '')),
            ])
            ->intro()
            ->items(OrderMail::items($items, withStore: false), 'Items to ship')
            ->totals([
                ['Items', PanmotiveMail::money($items->sum('line_total'))],
                ['Panmotive fee', '−'.PanmotiveMail::money($items->sum('platform_fee_amount'))],
                ['You’ll earn', PanmotiveMail::money($earn), true],
            ])
            ->facts([
                'Order number' => $this->order->order_number,
                'Ship by' => $shipBy?->format('D, M j, Y'),
                'Ship to' => trim(($ship['city'] ?? '').(! empty($ship['state']) ? ', '.$ship['state'] : '')),
            ])
            ->button($first ? route('seller.orders.show', $first) : route('seller.orders.index', ['status' => 'new']))
            ->outro()
            ->reason('You’re getting this email because you sell on Panmotive.');
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('New order received')
            ->body("Order {$this->order->order_number} is waiting for you to accept.")
            ->action('View order', 'view_order')
            ->data(['url' => route('seller.orders.index')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'order',
            'title' => "New order {$this->order->order_number}",
            'body' => 'A buyer checked out — accept it to start fulfilment.',
            'url' => route('seller.orders.index', ['status' => 'new']),
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
        ];
    }
}
