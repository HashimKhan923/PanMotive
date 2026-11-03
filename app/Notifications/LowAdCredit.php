<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Warns a seller their ad wallet is nearly (or completely) empty, so ads don't silently stop. */
class LowAdCredit extends Notification
{
    use Queueable;

    public function __construct(public float $balance, public bool $paused) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'ad_credit',
            'title' => $this->paused ? 'Your ads are paused — out of ad credit' : 'Ad credit running low',
            'body' => $this->paused
                ? 'Top up to switch them back on automatically.'
                : 'Balance is $'.number_format($this->balance, 2).'. Top up so your ads keep running.',
            'url' => route('seller.ad-credit.index'),
        ];
    }
}
