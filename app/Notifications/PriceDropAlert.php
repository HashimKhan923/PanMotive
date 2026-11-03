<?php

namespace App\Notifications;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PriceDropAlert extends Notification
{
    use Queueable;

    public function __construct(
        public Product $product,
        public float $oldPrice,
        public float $newPrice,
    ) {}

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
            'type' => 'price_drop',
            'product_id' => $this->product->id,
            'product' => $this->product->title,
            'slug' => $this->product->slug,
            'old_price' => round($this->oldPrice, 2),
            'new_price' => round($this->newPrice, 2),
            'drop_pct' => $this->oldPrice > 0 ? round(($this->oldPrice - $this->newPrice) / $this->oldPrice * 100) : 0,
        ];
    }
}
