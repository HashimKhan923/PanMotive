<?php

namespace App\Notifications;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LowStockAlert extends Notification
{
    use Queueable;

    public function __construct(public Product $product) {}

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
            'type' => 'low_stock',
            'product_id' => $this->product->id,
            'product' => $this->product->title,
            'quantity' => (int) $this->product->quantity,
            'reorder_point' => (int) $this->product->reorder_point,
            'reorder_qty' => $this->product->reorder_qty ? (int) $this->product->reorder_qty : null,
        ];
    }
}
