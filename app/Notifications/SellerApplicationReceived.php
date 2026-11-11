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
            ->subject('We received your seller application')
            ->preheader('Our team usually reviews applications within 1–2 business days.')
            ->line("Thanks for applying to sell on Panmotive as {$this->storeName}. Our team will review your application, usually within 1–2 business days.")
            ->progress(0, ['Applied', 'In review', 'Approved'])
            ->line('While you wait, you can finish your setup: add your shipping settings and connect payouts, so you can start selling the moment you’re approved.')
            ->action('Finish your setup', route('seller.onboarding.start'))
            ->reason('You’re getting this email because you applied to sell on Panmotive.');
    }
}
