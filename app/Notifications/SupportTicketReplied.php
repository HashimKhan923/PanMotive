<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SupportTicketReplied extends Notification
{
    use Queueable;

    public function __construct(public SupportTicket $ticket) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'support',
            'title' => "PanMotive support replied — {$this->ticket->ticket_number}",
            'body' => $this->ticket->subject,
            'url' => route('seller.support.index', ['ticket' => $this->ticket->id]),
            'ticket_id' => $this->ticket->id,
        ];
    }
}
