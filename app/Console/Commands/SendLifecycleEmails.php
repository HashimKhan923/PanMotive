<?php

namespace App\Console\Commands;

use App\Services\Marketing\LifecycleEmailService;
use Illuminate\Console\Command;

class SendLifecycleEmails extends Command
{
    protected $signature = 'emails:lifecycle';

    protected $description = 'Send Panmotive’s automatic emails: parts you looked at, add-your-car tip, did-it-fit check, win-back';

    public function handle(LifecycleEmailService $emails): void
    {
        foreach ($emails->run() as $type => $count) {
            $this->line(str_pad($type, 12).$count);
        }
    }
}
