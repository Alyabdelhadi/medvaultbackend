<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Schedule;

/*
| Needs one real cron entry on the server, which replaces WP-Cron (that only
| fired on site traffic):
|
|   * * * * * cd ~/medvault-backend && /usr/local/bin/ea-php84 artisan schedule:run >> /dev/null 2>&1
|
| The poller is checked every minute and runs when the interval set in the
| admin (poll_minutes, at least 5) has passed. Its own lock stops overlaps.
|
| Deliberately not runInBackground(): that starts a child process, and shared
| hosts commonly disable the functions it needs. A long poll simply holds this
| run; the next minute's run sees the lock and skips.
*/
Schedule::command('medvault:poll-results')->everyMinute();

Schedule::command('medvault:prune-logs')->dailyAt('03:30');

// A heartbeat, so the admin and medvault:doctor can warn when cron is not
// running the scheduler.
Schedule::call(fn () => Setting::put('scheduler_seen_at', now()->format('Y-m-d H:i:s')))
    ->everyMinute()->name('scheduler-heartbeat');
