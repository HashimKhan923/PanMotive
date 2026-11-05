<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Lets the admin health page see that the scheduler is really running.
Schedule::call(fn () => \App\Support\Heartbeat::beat('scheduler'))->everyMinute()->name('system-heartbeat');

Schedule::command('orders:auto-confirm-deliveries')->daily();
Schedule::command('carts:send-abandoned-reminders')->hourly();
Schedule::command('marketplace:maintain')->hourly();

// Backups (spatie/laravel-backup → s3-backups disk).
Schedule::command('backup:clean')->dailyAt('01:00');
Schedule::command('backup:run')->dailyAt('02:00');
Schedule::command('backup:monitor')->dailyAt('03:00');
