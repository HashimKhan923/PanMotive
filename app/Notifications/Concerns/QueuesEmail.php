<?php

namespace App\Notifications\Concerns;

use NotificationChannels\WebPush\WebPushChannel;

/**
 * Emails go through the queue, so a slow or failing mail service never slows down or breaks
 * checkout, shipping or a webhook (the worker retries it). The in-app bell and push alerts
 * stay instant.
 */
trait QueuesEmail
{
    public $deleteWhenMissingModels = true;

    public function viaConnections(): array
    {
        return [
            'mail' => config('queue.default'),
            'database' => 'sync',
            WebPushChannel::class => 'sync',
        ];
    }
}
