# MedVault backend

Laravel 13 + PostgreSQL replacement for the WordPress plugin behind the MedVault
app. See `CLAUDE.md` for the rules this port has to keep.

## Status

Done:

1. Schema, models and the WordPress import.
2. Auth and accounts at the WordPress paths: `/register`, `/login`, `/dg/register`,
   `/profile`, `/profile/update`, `/account/delete`, `/otp/send`, `/otp/verify`,
   `/password/reset`, `/lab-token/refresh`, plus the lab-login half of the engine
   (per-lab config, token fan-out, throttle back-off).

3. Public directory and content: `/labs`, `/labs/{id}`, `/sliders`, `/banners`,
   `/result-categories`, `/about`, `/faq`, `/phone`, all checked against the live
   WordPress output (`tests/Fixtures/wordpress-live`), and the photo upload at
   `/wp-json/wp/v2/media`.

4. Lab results: `/lab-results` (12 s inline budget, then lazy), `/lab-results/{nb}`,
   signed `/lab-file` proxying, `/dg/requests` and `/ai-summary`. Row normalization
   is held byte-for-byte to the plugin via golden files (`tools/plugin-golden.php`).

5. Notifications: `/device/register`, `/device/unregister`, `/notifications`,
   `/notifications/read`, FCM v1 (topic broadcast and per-device), and the
   result-ready poller (`medvault:poll-results`, scheduled every minute, runs
   when the admin interval has passed).

Next: the admin panel (labs and their API settings, content, push and result
alerts, AI switch, lab managers, patients/doctors/guarantors with CSV export).

## Deploying the scheduler

One cron entry replaces WP-Cron, which only fired on site traffic:

```
* * * * * cd /path/to/medvault-backend && php artisan schedule:run >> /dev/null 2>&1
```

While WordPress is still live, keep only one poller running (turn polling off
in WordPress or here), or patients get every alert twice.

## Parity with the plugin

```bash
php tools/plugin-golden.php --nova=../Nova \
    --formatting=/path/to/wordpress/wp-includes/formatting.php \
    --kit=~/Downloads/MedVault-Dev-Kit/Data/Seed
php artisan test --filter=PluginParityTest
```

The synthetic golden is committed; the vendor-kit one lands in `storage/testing`
(ignored by git, as the kit may hold real patient names).

## Importing from WordPress

Always import from a **copy** of the WordPress database.

1. Restore a dump of the WordPress database into a MySQL you control and set
   `WP_DB_*` in `.env` (see `.env.example`).
2. Set `APP_TIMEZONE` to the WordPress site's timezone, and set a stable `APP_KEY`.
   The key encrypts lab tokens and credentials; keep it with the backups.
3. `php artisan migrate`
4. `php artisan medvault:import-wordpress --site-url=https://medvaultapp.com/app`
5. Read every warning it prints.

Re-running on a populated database needs `--replace`, which clears every
imported table first. Do this only before cutover: afterwards it would overwrite
passwords and tokens changed since.

What is carried over: users (ids, password hashes, bearer tokens, roles,
lab-manager scoping), labs (directory and results-API settings), per-lab
accounts (tokens, refusal counters, seen-status maps, poll errors), FCM devices,
the notification log, sliders, banners, result categories, the About/FAQ pages,
settings, Firebase credentials and unexpired cached AI summaries.

What is not: OTP codes, rate-limit counters and lab back-off windows (minutes
long, rebuilt by use), and media files. Lab images, sliders and profile photos
are URLs that still point at the WordPress uploads folder, which must stay
reachable or be copied.
