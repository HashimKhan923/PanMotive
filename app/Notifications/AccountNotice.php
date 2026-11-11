<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\QueuesEmail;
use App\Notifications\Messages\PanmotiveMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** A message from Panmotive about someone's account: suspension, payout hold, listing removal and so on. */
class AccountNotice extends Notification implements ShouldQueue
{
    use Queueable, QueuesEmail;

    public function __construct(public string $subject, public string $body, public ?string $url = null) {}

    public function via(object $notifiable): array
    {
        return $notifiable instanceof User ? ['mail', 'database'] : ['mail'];
    }

    public function toMail(object $notifiable): PanmotiveMail
    {
        $mail = PanmotiveMail::to($notifiable)
            ->subject($this->subject)
            ->preheader($this->subject)
            ->line($this->body)
            ->reason('This is a message from the Panmotive team about your account.');

        if ($notifiable instanceof User && $notifiable->hasRole('seller')) {
            $mail->forSeller();
        }

        return $this->url ? $mail->action('View details', $this->url) : $mail;
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->subject, 'body' => $this->body, 'url' => $this->url];
    }
}
