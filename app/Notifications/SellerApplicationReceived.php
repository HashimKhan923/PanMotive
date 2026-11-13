<?php

namespace App\Notifications;

use App\Notifications\Concerns\QueuesEmail;
use App\Notifications\Messages\PanmotiveMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** Confirms we have the seller application and says what happens next. */
class SellerApplicationReceived extends Notification implements ShouldQueue
{
    use Queueable, QueuesEmail;

    public function __construct(public string $storeName) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): PanmotiveMail
    {
        return PanmotiveMail::to($notifiable)
            ->forSeller()
            ->useWords('application_received', ['store' => $this->storeName])
            ->intro()
            ->progress(0, ['Applied', 'In review', 'Approved'])
            ->button(route('seller.onboarding.start'))
            ->outro()
            ->reason('You’re getting this email because you applied to sell on Panmotive.');
    }
}
