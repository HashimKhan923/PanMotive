<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A message from PanMotive about someone's account: suspension, payout hold, listing removal and so on. */
class AccountNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $subject, public string $body, public ?string $url = null) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject)->line($this->body);

        return $this->url ? $mail->action('View details', $this->url) : $mail;
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->subject, 'body' => $this->body, 'url' => $this->url];
    }
}
