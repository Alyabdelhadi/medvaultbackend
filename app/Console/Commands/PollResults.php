<?php

namespace App\Console\Commands;

use App\Services\Notifications\ResultPoller;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('medvault:poll-results {--force : Run now, even if polling is disabled or not yet due}')]
#[Description('Poll one batch of patients for newly ready lab results and notify them')]
class PollResults extends Command
{
    public function handle(ResultPoller $poller): int
    {
        if (! $this->option('force') && ! ResultPoller::isDue()) {
            return self::SUCCESS;
        }

        $stats = $poller->run((bool) $this->option('force'));

        // A finished run also has a "skipped" key: the count of users skipped.
        // The plugin's Run Poll Now tested isset() and so always took this branch.
        if (($stats['skipped'] ?? null) === true) {
            $this->components->warn($stats['message']);

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Polled %d of %d users (from offset %d): %d notification(s) sent, %d seeded, %d expired token(s), %d error(s).',
            $stats['polled'], $stats['total_users'], $stats['offset'], $stats['notified'], $stats['seeded'], $stats['unauthorized'], $stats['errors'],
        ));

        return self::SUCCESS;
    }
}
