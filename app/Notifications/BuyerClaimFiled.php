<?php

namespace App\Notifications;

use App\Models\BuyerClaim;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BuyerClaimFiled extends Notification
{
    use Queueable;

    public function __construct(public BuyerClaim $claim) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'buyer_claim_filed',
            'claim_number' => $this->claim->claim_number,
            'reason' => $this->claim->reason,
            'amount' => (float) $this->claim->amount,
            'respond_by' => $this->claim->respond_by?->toIso8601String(),
        ];
    }
}
