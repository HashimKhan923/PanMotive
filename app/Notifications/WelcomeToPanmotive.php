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
            ->subject('Welcome to Panmotive')
            ->preheader('Find the right part for your vehicle, from trusted US sellers.')
            ->line('Thanks for joining Panmotive, the marketplace for auto parts from trusted sellers across the US.')
            ->facts([
                'Add your vehicle' => 'See only parts that fit',
                'Make an offer' => 'On listings that allow it',
                'Buyer protection' => 'Get it as described, or your money back',
            ], 'Get the most out of Panmotive')
            ->action('Start shopping', config('app.url'))
            ->line('Have parts to sell? You can open a store from your account at any time.')
            ->reason('You’re getting this email because you created a Panmotive account with this address.');
    }
}
