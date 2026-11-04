<?php

namespace App\Support\Media;

use Illuminate\Support\Facades\Http;
use Spatie\MediaLibrary\Downloaders\Downloader;
use Spatie\MediaLibrary\MediaCollections\Exceptions\UnreachableUrl;

/**
 * Replaces Spatie's DefaultDownloader, which opens remote URLs with a bare
 * fopen() stream that has NO timeout — one slow/hanging image URL can block a
 * request until PHP's max_execution_time kills it (a bulk import with several
 * "Image Src" URLs hit this: 500 "Maximum execution time of 60 seconds exceeded").
 * This bounds every remote fetch to a few seconds and fails fast instead.
 */
class TimeoutDownloader implements Downloader
{
    public function getTempFile(string $url): string
    {
        try {
            $response = Http::timeout(8)->connectTimeout(4)
                ->withUserAgent('Panmotive Media Fetcher')
                ->withOptions(['verify' => config('media-library.media_downloader_ssl', true)])
                ->get($url);
        } catch (\Throwable $e) {
            throw UnreachableUrl::create($url);
        }

        if (! $response->successful()) {
            throw UnreachableUrl::create($url);
        }

        $temporaryFile = tempnam(sys_get_temp_dir(), 'media-library');
        file_put_contents($temporaryFile, $response->body());

        return $temporaryFile;
    }
}
