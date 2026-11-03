<?php

namespace App\Notifications;

use App\Models\ReturnRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SellerReturnRequested extends Notification
{
    use Queueable;

    public function __construct(public ReturnRequest $return) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'return',
            'title' => "Return requested: {$this->return->rma_number}",
            'body' => "{$this->return->orderItem->product_title} — {$this->return->reason}",
            'url' => route('seller.returns.index'),
            'return_id' => $this->return->id,
        ];
    }
}
