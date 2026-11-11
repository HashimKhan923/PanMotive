<?php

namespace App\Notifications;

use App\Notifications\Concerns\QueuesEmail;
use App\Notifications\Messages\PanmotiveMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** The seller application was not approved, with the reason. */
class SellerRejected extends Notification implements ShouldQueue
{
    use Queueable, QueuesEmail;

    public function __construct(public string $reason) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable->email ? ['mail', 'database'] : ['database'];
    }

    public function toMail(object $notifiable): PanmotiveMail
    {
        return PanmotiveMail::to($notifiable)
            ->forSeller()
            ->subject('Update on your Panmotive seller application')
            ->preheader('We couldn’t approve your application this time.')
            ->line('Thank you for applying to sell on Panmotive. We reviewed your application and couldn’t approve it this time.')
            ->quote($this->reason, 'Reason')
            ->line('If you can fix this, or think we got it wrong, just reply to this email and our team will take another look.')
            ->reason('You’re getting this email because you applied to sell on Panmotive.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['type' => 'seller_rejected', 'reason' => $this->reason];
    }
}
