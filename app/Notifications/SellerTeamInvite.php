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

        $role = ucfirst(str_replace('_', ' ', (string) $this->member->role));

        return PanmotiveMail::to($notifiable)
            ->forSeller()
            ->useWords('team_invite', ['store' => $store, 'role' => $role, 'days' => $days])
            ->intro()
            ->facts(['Store' => $store, 'Your role' => $role, 'Invitation expires' => now()->addDays($days)->format('M j, Y')])
            ->button($url)
            ->outro()
            ->reason('Not expecting this? You can ignore this email. Nothing happens unless you accept.');
    }
}
