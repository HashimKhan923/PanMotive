<?php

namespace App\Notifications;

use App\Models\ReturnRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Keeps the buyer informed at every step of their return. */
class ReturnUpdated extends Notification
{
    use Queueable;

    public function __construct(public ReturnRequest $return, public string $step, public ?float $refunded = null) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    private function headline(): string
    {
        return match ($this->step) {
            'approved' => 'Your return was approved — please send the item back',
            'rejected' => 'Your return request was declined',
            'received' => 'The seller received your return',
            'refunded' => 'Your refund is on its way',
            default => 'Update on your return',
        };
    }

    public function toMail(object $notifiable): MailMessage
    {
        $r = $this->return;
        $mail = (new MailMessage)
            ->subject($this->headline()." ({$r->rma_number})")
            ->line("Return {$r->rma_number} for \"{$r->orderItem->product_title}\".");

        if ($this->step === 'approved' && $r->resolution_note) {
            $mail->line('Return instructions from the seller:')->line($r->resolution_note);
        } elseif ($this->step === 'rejected') {
            $mail->line('Reason: '.($r->resolution_note ?: 'not given'))
                ->line('If you disagree, you can escalate the return to Panmotive from your order page.');
        } elseif ($this->step === 'refunded') {
            $mail->line('We\'ve refunded $'.number_format((float) $this->refunded, 2).' to your original payment method / store credit. Card refunds usually appear within 5–10 business days.');
        }

        return $mail->action('View your order', route('orders.show', $r->orderItem->order));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'return_'.$this->step,
            'title' => $this->headline(),
            'body' => "{$this->return->rma_number} · {$this->return->orderItem->product_title}"
                .($this->refunded ? ' · $'.number_format($this->refunded, 2).' refunded' : ''),
            'return_id' => $this->return->id,
        ];
    }
}
