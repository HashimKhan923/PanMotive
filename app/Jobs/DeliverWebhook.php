<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Integrations\WebhookUrlGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public array $backoff = [30, 120, 600];

    public function __construct(public int $endpointId, public array $payload) {}

    public function handle(): void
    {
        $endpoint = WebhookEndpoint::find($this->endpointId);

        if (! $endpoint || ! $endpoint->is_active) {
            return;
        }

        // Re-check right before calling out: the host's DNS may now point somewhere private.
        if ($problem = WebhookUrlGuard::problem($endpoint->url)) {
            $this->record($endpoint, 'blocked', false, null, $problem);
            $endpoint->update(['is_active' => false, 'last_status' => 'disabled: '.$problem]);

            return;
        }

        $body = json_encode($this->payload, JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $body, $endpoint->secret);
        $started = microtime(true);

        try {
            $response = Http::timeout(8)
                ->withoutRedirecting() // a redirect could bounce the request to an internal address
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Panmotive-Event' => $this->payload['event'],
                    'X-Panmotive-Signature' => $signature,
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint->url);

            $ok = $response->successful();
            $this->record($endpoint, (string) $response->status(), $ok, $started);

            $endpoint->forceFill([
                'last_status' => (string) $response->status(),
                'last_delivered_at' => now(),
                'failure_count' => $ok ? 0 : $endpoint->failure_count + 1,
            ])->save();

            if (! $ok) {
                $this->release(60);
            }

            // Auto-disable a chronically failing endpoint.
            if ($endpoint->fresh()->failure_count >= 15) {
                $endpoint->update(['is_active' => false, 'last_status' => 'disabled after repeated failures']);
            }
        } catch (\Throwable $e) {
            $this->record($endpoint, 'error', false, $started, substr($e->getMessage(), 0, 250));
            $endpoint->forceFill(['last_status' => 'error: '.substr($e->getMessage(), 0, 80), 'failure_count' => $endpoint->failure_count + 1])->save();
            $this->release(120);
        }
    }

    private function record(WebhookEndpoint $endpoint, string $status, bool $ok, ?float $started, ?string $error = null): void
    {
        WebhookDelivery::create([
            'webhook_endpoint_id' => $endpoint->id,
            'event' => $this->payload['event'] ?? 'unknown',
            'status' => $status,
            'success' => $ok,
            'duration_ms' => $started ? (int) round((microtime(true) - $started) * 1000) : null,
            'error' => $error,
        ]);

        // Keep the log small: the last 50 attempts per endpoint.
        $keep = WebhookDelivery::where('webhook_endpoint_id', $endpoint->id)->latest('id')->skip(50)->value('id');
        if ($keep) {
            WebhookDelivery::where('webhook_endpoint_id', $endpoint->id)->where('id', '<=', $keep)->delete();
        }
    }
}
