<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use App\Notifications\Concerns\QueuesEmail;
use App\Notifications\Messages\PanmotiveMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class SupportTicketReplied extends Notification implements ShouldQueue
{
    use Queueable, QueuesEmail;

    public function __construct(public SupportTicket $ticket) {}

    public function via(object $notifiable): array
    {
        return ($notifiable->email ?? null) ? ['mail', 'database'] : ['database'];
    }

    public function toMail(object $notifiable): PanmotiveMail
    {
        $reply = $this->ticket->messages()->where('author_role', 'support')->latest('id')->first();
        $url = route('seller.support.index', ['ticket' => $this->ticket->id]);

        $mail = PanmotiveMail::to($notifiable)
            ->forSeller()
            ->subject("Panmotive support replied — {$this->ticket->ticket_number}")
            ->preheader(Str::limit((string) $reply?->body, 120))
            ->line("Our support team replied to your ticket “{$this->ticket->subject}”.");

        if ($reply) {
            $mail->quote(Str::limit($reply->body, 1500), 'Panmotive support');
        }

        return $mail->action('View and reply', $url)
            ->facts(['Ticket' => $this->ticket->ticket_number])
            ->reason('Please reply from your seller dashboard so the whole conversation stays on your ticket.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'support',
            'title' => "Panmotive support replied — {$this->ticket->ticket_number}",
            'body' => $this->ticket->subject,
            'url' => route('seller.support.index', ['ticket' => $this->ticket->id]),
            'ticket_id' => $this->ticket->id,
        ];
    }
}
