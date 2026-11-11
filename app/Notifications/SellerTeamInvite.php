<?php

namespace App\Notifications;

use App\Models\SellerTeamMember;
use App\Notifications\Concerns\QueuesEmail;
use App\Notifications\Messages\PanmotiveMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class SellerTeamInvite extends Notification implements ShouldQueue
{
    use Queueable, QueuesEmail;

    public function __construct(public SellerTeamMember $member) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): PanmotiveMail
    {
        $store = $this->member->owner->sellerProfile?->business_name ?? 'a Panmotive store';
        $url = route('seller.team.accept', $this->member->invite_token);
        $days = \App\Services\Seller\TeamService::INVITE_TTL_DAYS;

        return PanmotiveMail::to($notifiable)
            ->greeting('Hello!')
            ->forSeller()
            ->subject("You've been invited to help run {$store} on Panmotive")
            ->preheader("{$store} added you to their seller team.")
            ->line("{$store} has invited you to their seller team with the role of \"{$this->member->role}\".")
            ->facts(['Store' => $store, 'Your role' => ucfirst(str_replace('_', ' ', (string) $this->member->role)), 'Invitation expires' => now()->addDays($days)->format('M j, Y')])
            ->action('Accept invitation', $url)
            ->line('If you don\'t have a Panmotive account yet, you\'ll be able to create one — use this same email address.')
            ->line('This invitation expires in '.$days.' days.')
            ->reason('Not expecting this? You can ignore this email. Nothing happens unless you accept.');
    }
}
