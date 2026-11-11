<?php

namespace App\Notifications;

use App\Models\Message;
use App\Notifications\Concerns\QueuesEmail;
use App\Notifications\Messages\PanmotiveMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class NewMessageReceived extends Notification implements ShouldQueue
{
    use Queueable, QueuesEmail;

    /** $email: only the first unread message in a thread is emailed (see MessagingService::send). */
    public function __construct(public Message $message, public bool $email = false) {}

    public function via(object $notifiable): array
    {
        return $this->email && $notifiable->email ? ['mail', 'database'] : ['database'];
    }

    private function isSeller(object $notifiable): bool
    {
        return $this->message->conversation->seller_id === $notifiable->id;
    }

    private function url(object $notifiable): string
    {
        $id = $this->message->conversation->id;

        return $this->isSeller($notifiable)
            ? route('seller.messages.index', ['c' => $id])
            : route('messages.index', ['c' => $id]);
    }

    private function senderName(object $notifiable): string
    {
        $conversation = $this->message->conversation;

        // Buyers see the store name, not the person behind it.
        return $this->isSeller($notifiable)
            ? ($this->message->sender?->name ?? 'a buyer')
            : ($conversation->seller?->sellerProfile?->business_name ?? $this->message->sender?->name ?? 'the seller');
    }

    public function toMail(object $notifiable): PanmotiveMail
    {
        $conversation = $this->message->conversation;
        $from = $this->senderName($notifiable);
        $photos = count((array) $this->message->attachments);

        $mail = PanmotiveMail::to($notifiable)
            ->subject('New message from '.$from.($conversation->subject ? " — {$conversation->subject}" : ''))
            ->preheader(Str::limit($this->message->body, 120))
            ->line("{$from} sent you a message".($conversation->subject ? " about “{$conversation->subject}”" : '').':')
            ->quote(Str::limit($this->message->body, 600), $from);

        if ($photos) {
            $mail->line("Includes {$photos} photo".($photos === 1 ? '' : 's').'. Open the message to see '.($photos === 1 ? 'it' : 'them').'.');
        }

        if ($this->isSeller($notifiable)) {
            $mail->forSeller()->line('Buyers expect an answer within '.config('marketplace.messages.response_target_hours', 24).' hours. Your response time shows on your seller scorecard.');
        }

        return $mail->action('Reply on Panmotive', $this->url($notifiable))
            ->line('For your safety, keep conversations and payments on Panmotive. We never ask for your password or card details by email.')
            ->reason('Replying to this email won’t reach '.$from.'. Use the button above to reply.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'message',
            'title' => 'New message from '.$this->senderName($notifiable),
            'body' => Str::limit($this->message->body, 90),
            'conversation_id' => $this->message->conversation->id,
            'url' => $this->url($notifiable),
        ];
    }
}
