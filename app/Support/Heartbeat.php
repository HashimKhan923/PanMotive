<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Remembers when something last happened (worker alive, schedule ran, webhook arrived), so the admin
 * health page can show what is really running. Kept as small files, not in the cache, so the
 * "Clear caches" button can't make a healthy worker look dead.
 */
class Heartbeat
{
    /** Tests point this at a temporary folder. */
    public static ?string $directory = null;

    public static function beat(string $name): void
    {
        $dir = self::directory();

        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // This must never break the thing being watched, such as a payment webhook.
        @touch($dir.DIRECTORY_SEPARATOR.$name, now()->getTimestamp());
    }

    public static function lastSeen(string $name): ?Carbon
    {
        $path = self::directory().DIRECTORY_SEPARATOR.$name;
        clearstatcache(true, $path);
        $time = @filemtime($path);

        return $time ? Carbon::createFromTimestamp($time) : null;
    }

    private static function directory(): string
    {
        return self::$directory ?? storage_path('framework/cache/heartbeats');
    }
}
