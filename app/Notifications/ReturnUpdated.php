<?php

namespace App\Notifications;

use App\Models\ReturnRequest;
use App\Notifications\Concerns\QueuesEmail;
use App\Notifications\Messages\PanmotiveMail;
use App\Support\OrderMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** Keeps the buyer informed at every step of their return. */
class ReturnUpdated extends Notification implements ShouldQueue
{
    use Queueable, QueuesEmail;

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

    public function toMail(object $notifiable): PanmotiveMail
    {
        $r = $this->return;
        $item = $r->orderItem;

        $mail = PanmotiveMail::to($notifiable)
            ->subject($this->headline()." ({$r->rma_number})")
            ->preheader("Return {$r->rma_number} for {$item->product_title}.")
            ->line("Return {$r->rma_number} for \"{$item->product_title}\".")
            ->progress(match ($this->step) {
                'approved' => 1, 'received' => 2, 'refunded' => 3, default => 0,
            }, ['Requested', 'Approved', 'Received', 'Refunded'])
            ->items(OrderMail::items([$item]));

        if ($this->step === 'approved') {
            $mail->line('Pack the item securely and send it back to the seller. Keep the tracking number until your refund arrives.');
            if ($r->resolution_note) {
                $mail->quote($r->resolution_note, 'Return instructions from the seller');
            }
        } elseif ($this->step === 'rejected') {
            $mail->quote($r->resolution_note ?: 'No reason given.', 'Reason')
                ->line('If you disagree, you can escalate the return to Panmotive from your order page and our team will review it.');
        } elseif ($this->step === 'received') {
            $mail->line('The seller is checking the item. You’ll get another email when your refund is sent.');
        } elseif ($this->step === 'refunded') {
            $mail->facts(['Refunded' => PanmotiveMail::money($this->refunded)])
                ->line('We’ve refunded you the way you paid: to your card and/or your Panmotive store credit. Card refunds usually appear within 5–10 business days. Store credit is ready right away.');
        }

        return $mail->action('View your order', route('orders.show', $item->order))
            ->reason('You’re getting this email because you asked to return an item on Panmotive.');
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
