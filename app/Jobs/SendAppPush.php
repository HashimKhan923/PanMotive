<?php

namespace App\Jobs;

use App\Models\AppPushToken;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

/**
 * Sends one push to every phone a shopper is signed in on, through Expo's push service (which talks
 * to Apple and Google for us). Phones that uninstalled the app are forgotten.
 */
class SendAppPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120];

    /** @param array<string, mixed> $data */
    public function __construct(public int $userId, public string $title, public string $body, public array $data = []) {}

    public function handle(): void
    {
        $tokens = AppPushToken::where('user_id', $this->userId)->pluck('token')->all();
        if (! $tokens) {
            return;
        }

        $messages = array_map(fn ($to) => [
            'to' => $to,
            'title' => $this->title,
            'body' => $this->body,
            'data' => $this->data,
            'sound' => 'default',
            'channelId' => 'orders',
            'priority' => 'high',
        ], $tokens);

        $request = Http::timeout(15)->acceptJson()->asJson();
        if ($token = config('services.expo.access_token')) {
            $request = $request->withToken($token);
        }

        foreach (array_chunk($messages, 100) as $chunk) {
            $tickets = $request->post('https://exp.host/--/api/v2/push/send', $chunk)->throw()->json('data') ?? [];

            foreach ($tickets as $i => $ticket) {
                // The app was deleted or notifications were switched off: stop sending to that phone.
                if (($ticket['status'] ?? '') === 'error' && ($ticket['details']['error'] ?? '') === 'DeviceNotRegistered') {
                    AppPushToken::where('token', $chunk[$i]['to'])->delete();
                }
            }
        }
    }
}
