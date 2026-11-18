<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        // Public files (product photos, logos, banners, social images). With MEDIA_DRIVER=r2 they live
        // in a public Cloudflare R2 bucket served from MEDIA_URL (e.g. https://media.panmotive.com);
        // otherwise on this server under storage/app/public, served from /storage.
        'public' => env('MEDIA_DRIVER', 'local') === 'r2' ? [
            'driver' => 's3',
            'key' => env('MEDIA_AWS_ACCESS_KEY_ID'),
            'secret' => env('MEDIA_AWS_SECRET_ACCESS_KEY'),
            'region' => env('MEDIA_AWS_DEFAULT_REGION', 'auto'),
            'bucket' => env('MEDIA_AWS_BUCKET'),
            'endpoint' => env('MEDIA_AWS_ENDPOINT'),
            'url' => rtrim((string) env('MEDIA_URL'), '/'),
            'use_path_style_endpoint' => true,
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
            'throw' => false,
            'report' => false,
        ] : [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // Always the copy on this server (used to move files to R2, and as a fallback).
        'public-local' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // Backup target — MUST be a different bucket/account from the primary
        // 's3' disk so a compromise or fat-finger on primary can't touch backups.
        's3-backups' => [
            'driver' => 's3',
            'key' => env('BACKUP_AWS_ACCESS_KEY_ID'),
            'secret' => env('BACKUP_AWS_SECRET_ACCESS_KEY'),
            'region' => env('BACKUP_AWS_DEFAULT_REGION'),
            'bucket' => env('BACKUP_AWS_BUCKET'),
            'endpoint' => env('BACKUP_AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('BACKUP_AWS_USE_PATH_STYLE_ENDPOINT', false),
            // Cloudflare R2 and other S3-compatible stores: only send checksums when required.
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
