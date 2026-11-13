<?php

namespace App\Notifications;

use App\Notifications\Concerns\QueuesEmail;
use App\Notifications\Messages\PanmotiveMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** The seller application was approved: welcome, and what to do first. */
class SellerApproved extends Notification implements ShouldQueue
{
    use Queueable, QueuesEmail;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable->email ? ['mail', 'database'] : ['database'];
    }

    public function toMail(object $notifiable): PanmotiveMail
    {
        $store = $notifiable->sellerProfile?->business_name;

        return PanmotiveMail::to($notifiable)
            ->forSeller()
            ->useWords('seller_approved', ['store' => $store ?: 'your store'])
            ->intro()
            ->facts([
                '1. Connect payouts' => 'So we can pay you',
                '2. Add your first listing' => 'Photos, price, fitment',
                '3. Set your shipping' => 'Carrier and handling time',
            ], 'Get set up in 3 steps')
            ->button(route('seller.dashboard'))
            ->outro()
            ->reason('You’re getting this email because you applied to sell on Panmotive.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['type' => 'seller_approved'];
    }
}
