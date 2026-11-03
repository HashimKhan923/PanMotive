<?php

namespace App\Notifications;

use App\Models\SellerTeamMember;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SellerTeamInvite extends Notification
{
    use Queueable;

    public function __construct(public SellerTeamMember $member) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $store = $this->member->owner->sellerProfile?->business_name ?? 'a Pan Motive store';
        $url = route('seller.team.accept', $this->member->invite_token);

        return (new MailMessage)
            ->subject("You've been invited to help run {$store} on Pan Motive")
            ->greeting('Hello!')
            ->line("{$store} has invited you to their seller team with the role of \"{$this->member->role}\".")
            ->action('Accept invitation', $url)
            ->line('If you don\'t have a Pan Motive account yet, you\'ll be able to create one — use this same email address.')
            ->line('This invitation expires in '.\App\Services\Seller\TeamService::INVITE_TTL_DAYS.' days.');
    }
}
