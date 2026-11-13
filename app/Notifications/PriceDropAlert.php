<?php

namespace App\Notifications;

use App\Models\Product;
use App\Notifications\Concerns\QueuesEmail;
use App\Notifications\Messages\PanmotiveMail;
use App\Support\EmailPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PriceDropAlert extends Notification implements ShouldQueue
{
    use Queueable, QueuesEmail;

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
        return ($notifiable->email ?? null) && EmailPreferences::wants($notifiable, 'price_drops') ? ['mail', 'database'] : ['database'];
    }

    private function dropPct(): int
    {
        return $this->oldPrice > 0 ? (int) round(($this->oldPrice - $this->newPrice) / $this->oldPrice * 100) : 0;
    }

    public function toMail(object $notifiable): PanmotiveMail
    {
        $p = $this->product;

        return PanmotiveMail::to($notifiable)
            ->useWords('price_drop', [
                'product' => $p->title, 'old_price' => PanmotiveMail::money($this->oldPrice), 'new_price' => PanmotiveMail::money($this->newPrice),
                'percent' => $this->dropPct(), 'savings' => PanmotiveMail::money($this->oldPrice - $this->newPrice),
            ])
            ->intro()
            ->items([[
                'title' => $p->title,
                'meta' => 'Was '.PanmotiveMail::money($this->oldPrice),
                'price' => PanmotiveMail::money($this->newPrice),
                'image' => $p->getFirstMediaUrl('images', 'thumb') ?: null,
                'url' => route('products.show', $p->slug),
            ]])
            ->facts(['You save' => PanmotiveMail::money($this->oldPrice - $this->newPrice)." ({$this->dropPct()}%)"])
            ->button(route('products.show', $p->slug))
            ->outro()
            ->optional($notifiable, 'price_drops');
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
            'drop_pct' => $this->dropPct(),
        ];
    }
}
