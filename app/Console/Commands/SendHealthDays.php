<?php

namespace App\Console\Commands;

use App\Services\HealthDays\HealthDaySender;
use Illuminate\Console\Command;

class SendHealthDays extends Command
{
    protected $signature = 'medvault:health-days';

    protected $description = 'Send the awareness-day notifications that are due today (Beirut time)';

    public function handle(HealthDaySender $sender): int
    {
        foreach ($sender->sendDue() as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
