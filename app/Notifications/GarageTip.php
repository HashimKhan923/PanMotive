<?php

namespace App\Notifications;

use App\Notifications\Concerns\QueuesEmail;
use App\Notifications\Messages\PanmotiveMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** Second welcome email: add your vehicle, so you only see parts that fit. */
class GarageTip extends Notification implements ShouldQueue
{
    use Queueable, QueuesEmail;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): PanmotiveMail
    {
        return PanmotiveMail::to($notifiable)
            ->useWords('garage_tip')
            ->intro()
            ->facts([
                'See only parts that fit' => 'Filter every search to your vehicle',
                'Green “Fits” check' => 'On every listing made for your vehicle',
                'Fit Guarantee' => 'Doesn’t fit? Full refund, free return',
            ], 'Why add your vehicle')
            ->button(route('home').'#garage')
            ->outro()
            ->optional($notifiable, 'tips');
    }
}
