<?php

namespace App\Services\Integrations;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookEndpoint;

class WebhookDispatcher
{
    /** Queue a signed delivery to every active endpoint of $sellerId listening for $event. */
    public function fire(string $event, int $sellerId, array $data): void
    {
        $payload = [
            'event' => $event,
            'created_at' => now()->toIso8601String(),
            'data' => $data,
        ];

        WebhookEndpoint::where('seller_id', $sellerId)
            ->where('is_active', true)
            ->get()
            ->filter(fn ($e) => $e->listensFor($event))
            ->each(fn ($e) => DeliverWebhook::dispatch($e->id, $payload));
    }

    /**
     * Fire an order event once per seller, with that seller's lines only.
     *
     * @param  iterable<\App\Models\OrderItem>  $items
     */
    public function orderEvent(string $event, iterable $items, array $extra = []): void
    {
        collect($items)->groupBy('seller_id')->each(function ($lines, $sellerId) use ($event, $extra) {
            $first = $lines->first();
            $this->fire($event, (int) $sellerId, array_merge([
                'order_number' => $first->order?->order_number,
                'items' => $lines->map(fn ($i) => [
                    'order_item_id' => $i->id,
                    'product_id' => $i->product_id,
                    'title' => $i->product_title,
                    'quantity' => $i->quantity,
                    'line_total' => (float) $i->line_total,
                    'status' => $i->status?->value,
                ])->values()->all(),
            ], $extra));
        });
    }
}
