<?php

namespace App\Console\Commands;

use App\Services\Seller\SellerSignupService;
use Illuminate\Console\Command;

class RemindUnfinishedSellers extends Command
{
    protected $signature = 'sellers:remind-unfinished';

    protected $description = 'Remind people who started seller sign-up but never set up their store (once, a day later)';

    public function handle(SellerSignupService $signups): int
    {
        $this->info($signups->remindDue().' reminders sent');

        return self::SUCCESS;
    }
}
