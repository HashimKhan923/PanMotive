<?php

namespace App\Notifications;

use App\Models\ReturnRequest;
use App\Notifications\Concerns\QueuesEmail;
use App\Notifications\Messages\PanmotiveMail;
use App\Support\OrderMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class SellerReturnRequested extends Notification implements ShouldQueue
{
    use Queueable, QueuesEmail;

    public function __construct(public ReturnRequest $return) {}

    public function via(object $notifiable): array
    {
        return ($notifiable->email ?? null) ? ['mail', 'database'] : ['database'];
    }

    public function toMail(object $notifiable): PanmotiveMail
    {
        $r = $this->return->loadMissing('orderItem.order', 'orderItem.product.media', 'orderItem.variant.media');

        $mail = PanmotiveMail::to($notifiable)
            ->forSeller()
            ->subject("Return requested: {$r->rma_number}")
            ->preheader($r->respond_by ? 'Please respond by '.$r->respond_by->format('M j').'.' : 'A buyer asked to return an item.')
            ->line('A buyer asked to return an item from order '.$r->orderItem->order->order_number.'.')
            ->items(OrderMail::items([$r->orderItem], withStore: false))
            ->facts([
                'Return number' => $r->rma_number,
                'Reason' => ucfirst(str_replace('_', ' ', (string) $r->reason)),
                'Respond by' => $r->respond_by?->format('D, M j, Y'),
            ]);

        if ($r->detail) {
            $mail->quote($r->detail, 'What the buyer said');
        }

        return $mail->action('Review the return', route('seller.returns.index'))
            ->line('If you don’t respond in time, Panmotive may step in and decide the return for you.')
            ->reason('You’re getting this email because you sell on Panmotive.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'return',
            'title' => "Return requested: {$this->return->rma_number}",
            'body' => "{$this->return->orderItem->product_title} — {$this->return->reason}",
            'url' => route('seller.returns.index'),
            'return_id' => $this->return->id,
        ];
    }
}
