<?php

namespace App\Notifications;

use App\Models\BuyerClaim;
use App\Notifications\Concerns\QueuesEmail;
use App\Notifications\Messages\PanmotiveMail;
use App\Support\OrderMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** A buyer opened a claim against the seller (item not received, not as described…). */
class BuyerClaimFiled extends Notification implements ShouldQueue
{
    use Queueable, QueuesEmail;

    public function __construct(public BuyerClaim $claim) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ($notifiable->email ?? null) ? ['mail', 'database'] : ['database'];
    }

    public function toMail(object $notifiable): PanmotiveMail
    {
        $c = $this->claim->loadMissing('orderItem.order', 'orderItem.product.media', 'orderItem.variant.media');

        $mail = PanmotiveMail::to($notifiable)
            ->forSeller()
            ->error()
            ->subject("Action needed: claim {$c->claim_number} on order {$c->orderItem?->order?->order_number}")
            ->preheader($c->respond_by ? 'Respond by '.$c->respond_by->format('M j').' or the claim may be decided without you.' : 'A buyer opened a claim.')
            ->line('A buyer opened a claim about an item you sold. Please reply with your side, or refund the buyer to close it.');

        if ($c->orderItem) {
            $mail->items(OrderMail::items([$c->orderItem], withStore: false));
        }

        $mail->facts([
            'Claim number' => $c->claim_number,
            'Reason' => ucfirst(str_replace('_', ' ', (string) $c->reason)),
            'Amount' => PanmotiveMail::money($c->amount),
            'Respond by' => $c->respond_by?->format('D, M j, Y g:i A'),
        ]);

        if ($c->detail) {
            $mail->quote($c->detail, 'What the buyer said');
        }

        return $mail->action('Respond to the claim', route('seller.account-health.index'))
            ->line('If you don’t respond in time, Panmotive will decide the claim, which usually means a refund for the buyer.')
            ->reason('You’re getting this email because you sell on Panmotive.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'buyer_claim_filed',
            'claim_number' => $this->claim->claim_number,
            'reason' => $this->claim->reason,
            'amount' => (float) $this->claim->amount,
            'respond_by' => $this->claim->respond_by?->toIso8601String(),
        ];
    }
}
