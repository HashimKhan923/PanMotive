<?php

// Partial override of spatie/laravel-medialibrary's config — Laravel merges the
// package's own defaults in for every key we don't set here.

return [

    // The stock downloader uses a bare fopen() with no timeout, so one slow
    // remote image can hang a request until PHP kills it. See TimeoutDownloader.
    'media_downloader' => \App\Support\Media\TimeoutDownloader::class,

];
