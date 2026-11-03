<?php

namespace App\Notifications;

use App\Models\SellerProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class SellerAnnouncement extends Notification
{
    use Queueable;

    public function __construct(public SellerProfile $seller, public string $message) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'announcement',
            'title' => "{$this->seller->business_name} posted an update",
            'body' => Str::limit($this->message, 120),
            'url' => route('sellers.show', $this->seller),
        ];
    }
}
