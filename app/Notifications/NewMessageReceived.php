<?php

namespace App\Notifications;

use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class NewMessageReceived extends Notification
{
    use Queueable;

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

    public function toMail(object $notifiable): MailMessage
    {
        $conversation = $this->message->conversation;
        $mail = (new MailMessage)
            ->subject('New message from '.$this->senderName($notifiable).($conversation->subject ? " — {$conversation->subject}" : ''))
            ->line('"'.Str::limit($this->message->body, 400).'"');

        if ($this->message->attachments) {
            $mail->line('Includes '.count($this->message->attachments).' photo(s).');
        }

        if ($this->isSeller($notifiable)) {
            $mail->line('Buyers expect an answer within '.config('marketplace.messages.response_target_hours', 24).' hours — your response time shows on your seller scorecard.');
        }

        return $mail->action('Reply on Panmotive', $this->url($notifiable))
            ->line('For your safety, keep conversations and payments on Panmotive.');
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
