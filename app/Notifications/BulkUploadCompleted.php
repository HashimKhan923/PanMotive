<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Minimal stub — channels (mail/broadcast) are wired with the notification
 * system in Phase 7. Persists to the database so results aren't lost.
 */
class BulkUploadCompleted extends Notification
{
    use Queueable;

    /**
     * @param  array<int, string>  $errors
     */
    public function __construct(
        public int $created,
        public array $errors = [],
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
            'type' => 'bulk_upload_completed',
            'created' => $this->created,
            'error_count' => count($this->errors),
            'errors' => array_slice($this->errors, 0, 20),
        ];
    }
}
