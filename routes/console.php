<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Schedule;

/*
| Needs one real cron entry on the server, which replaces WP-Cron (that only
| fired on site traffic):
|
|   * * * * * cd /path/to/medvault-backend && php artisan schedule:run >> /dev/null 2>&1
|
| The poller is checked every minute and runs when the interval set in the
| admin (poll_minutes, at least 5) has passed. Its own lock stops overlaps.
*/
Schedule::command('medvault:poll-results')->everyMinute()->runInBackground();

// A heartbeat, so the admin can warn when cron is not running the scheduler.
Schedule::call(fn () => Setting::put('scheduler_seen_at', now()->format('Y-m-d H:i:s')))
    ->everyMinute()->name('scheduler-heartbeat');
