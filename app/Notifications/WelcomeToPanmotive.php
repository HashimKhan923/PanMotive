<?php

namespace App\Notifications;

use App\Notifications\Concerns\QueuesEmail;
use App\Notifications\Messages\PanmotiveMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** Sent once, right after someone creates an account. */
class WelcomeToPanmotive extends Notification implements ShouldQueue
{
    use Queueable, QueuesEmail;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): PanmotiveMail
    {
        return PanmotiveMail::to($notifiable)
            ->useWords('welcome')
            ->intro()
            ->facts([
                'Add your vehicle' => 'See only parts that fit',
                'Make an offer' => 'On listings that allow it',
                'Buyer protection' => 'Get it as described, or your money back',
            ], 'Get the most out of Panmotive')
            ->button(config('app.url'))
            ->outro()
            ->reason('You’re getting this email because you created a Panmotive account with this address.');
    }
}
