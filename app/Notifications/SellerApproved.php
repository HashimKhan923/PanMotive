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
            ->subject('You’re approved! Your Panmotive store is ready')
            ->preheader('Add your first listing and start selling.')
            ->line('Great news: '.($store ? "{$store} has" : 'your store has').' been approved, and you can start selling on Panmotive today.')
            ->facts([
                '1. Connect payouts' => 'So we can pay you',
                '2. Add your first listing' => 'Photos, price, fitment',
                '3. Set your shipping' => 'Carrier and handling time',
            ], 'Get set up in 3 steps')
            ->action('Go to your seller dashboard', route('seller.dashboard'))
            ->line('Listings with clear photos and exact fitment (year, make, model) sell faster and get fewer returns.')
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
