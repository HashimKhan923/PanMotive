<?php

namespace App\Services\Push;

use App\Http\Resources\Shop\AppLink;
use App\Jobs\SendAppPush;
use App\Models\AppPushToken;
use App\Models\User;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Push notifications to the shopping app. Rides on the notifications the site already sends: when a
 * shopper-facing notification goes out (shipped, delivered, price drop, a reply…), every phone the
 * shopper is signed in on gets a push too, which opens the matching screen in the app.
 */
class AppPush
{
    /** Notifications that are worth a buzz on the shopper's phone. */
    public const PUSHED = [
        \App\Notifications\OrderShipped::class,
        \App\Notifications\OrderDelivered::class,
        \App\Notifications\OrderItemCancelled::class,
        \App\Notifications\PriceDropAlert::class,
        \App\Notifications\BackInStock::class,
        \App\Notifications\NewMessageReceived::class,
        \App\Notifications\QuestionAnswered::class,
        \App\Notifications\ReturnUpdated::class,
        \App\Notifications\ClaimResolvedBySeller::class,
        \App\Notifications\OfferActivity::class,
        \App\Notifications\SubscriptionOrderPlaced::class,
        \App\Notifications\SellerRespondedToReview::class,
    ];

    public function handleSent(NotificationSent $event): void
    {
        $user = $event->notifiable;
        $notification = $event->notification;

        if (! $user instanceof User || ! in_array($notification::class, self::PUSHED, true) || ! method_exists($notification, 'toArray')) {
            return;
        }
        // A notification goes out on several channels (mail, database…): push it once.
        if (! Cache::add('app-push:'.($notification->id ?: spl_object_id($notification)).':'.$user->id, 1, now()->addHour())) {
            return;
        }
        if (! AppPushToken::where('user_id', $user->id)->exists()) {
            return;
        }

        $message = $this->message($notification->toArray($user));
        if ($message) {
            SendAppPush::dispatch($user->id, $message['title'], $message['body'], $message['data'])->afterCommit();
        }
    }

    /** @return array{title: string, body: string, data: array<string, mixed>}|null */
    public function message(array $payload): ?array
    {
        $type = (string) ($payload['type'] ?? '');

        if ($type === 'price_drop') {
            $title = 'Price drop: '.Str::limit((string) ($payload['product'] ?? 'a part you saved'), 60);
            $body = 'Now $'.number_format((float) ($payload['new_price'] ?? 0), 2).' (was $'.number_format((float) ($payload['old_price'] ?? 0), 2).')';
            $link = ['screen' => 'product', 'slug' => $payload['slug'] ?? null];
        } else {
            $title = trim((string) ($payload['title'] ?? ''));
            $body = trim((string) ($payload['body'] ?? ''));
            $link = AppLink::from($payload['url'] ?? null);
            if (! empty($payload['conversation_id'])) {
                $link = ['screen' => 'conversation', 'id' => (int) $payload['conversation_id']];
            }
        }

        if ($title === '') {
            return null;
        }

        return ['title' => Str::limit($title, 80), 'body' => Str::limit($body, 160), 'data' => ['type' => $type, 'link' => $link]];
    }
}
