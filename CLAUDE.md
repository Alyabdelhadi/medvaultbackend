# CLAUDE.md

**medvault-backend** is the Laravel replacement for the MedVault WordPress plugin
(`functions .php` in `../Nova`). The Flutter app in `../Nova` is the client.

The goal is a backend the existing app cannot tell apart from WordPress: same
paths (`/app/wp-json/custom/v1/*`, via a rewrite), same JSON, same ids, same
tokens. The lab-engine rules in `../Nova/CLAUDE.md` ("Multi-lab results APIs",
"Lab token lifecycle", "Notifications", "Result rows are lazy") are
requirements for this port, not background. Verdun's output must not change.

## Commands

```bash
export PATH=$HOME/development/medvault-local/bin:$PATH   # php, composer
php artisan test                                          # SQLite, in memory
DB_CONNECTION=pgsql DB_PORT=54329 DB_DATABASE=medvault_test DB_USERNAME=postgres \
  php artisan test                                        # the production engine
local-dev/start.sh                                        # portable PostgreSQL + server
php artisan medvault:import-wordpress [--replace] [--site-url=...]
vendor/bin/pint                                           # code style
```

PHP is a static build with no system install; Composer runs with
`curl_multi_exec` disabled because that build's curl aborts on HTTP/3.

## Load-bearing decisions

- **Ids are WordPress ids.** Users and labs keep their wp ids: they are in
  signed PDF links, `lab_id` on app requests, notifications and `lab_accounts`.
- **No `hashed` cast on `User::password`.** Imported hashes are `$wp$2y$`,
  `$P$` or md5, which Laravel does not recognise, so the cast would hash them
  again. `WordPressPassword::check()` verifies them; rehash to bcrypt on the
  first successful sign-in.
- **Bearer tokens are stored as sha256** (`users.api_token_hash`). The plaintext
  the app holds keeps working.
- **`lab_accounts.seen_status`: null means not seeded, `{}` means seeded and
  empty.** Losing the difference re-seeds the account and swallows every result
  that turned ready meanwhile. It is always a JSON object (`JsonMap` cast):
  request numbers that run 0..n must not be re-indexed into a list.
- **`lab_id` 0 is the legacy single-lab account**, not a row in `labs`; hence
  no foreign key on `lab_accounts.lab_id`.
- **Lab API fields are nullable** where WordPress told "never saved" apart from
  a value. Apply the plugin's defaults in code, quirks included: a saved
  priority of `0` meant `10` (`?: 10`).
- **Secrets are encrypted with APP_KEY**: lab tokens, lab API headers, Firebase
  credentials. Losing APP_KEY loses them; back it up with the database.
- **Times:** `notifications.created_at`, `lab_accounts.polled_at` and device
  times were site-local in WordPress. `APP_TIMEZONE` must match the old site.
- **WordPress request semantics on `wp-json/*`:** no `TrimStrings`, no
  `ConvertEmptyStringsToNull` (bootstrap/app.php). Passwords are compared as
  typed, "required" means "not null" (`ReadsWordPressParams`), and an empty
  `id_image` / `blood_type` is how the app clears one. Errors are `WpError`:
  `{code, message, data: {status}}`; unknown routes and wrong methods are
  `rest_no_route` 404.
- **Sanitizers are ports** (`Support/WordPress/Sanitize`). The app shows
  `message` verbatim, so validation order and wording follow the plugin, even
  where WordPress was lenient (an unusable email is dropped, not rejected).
- **`LabTokenService` is the only lab login.** Refused (`lab_login_failed`,
  `no_mobile`), throttled (`lab_rate_limited`, including Bekaa's 200 "Too many
  attempts") and unreachable (`external_api_error`) stay distinct; only a
  refusal increments `auth_failures`, and only "every lab refused" is
  `lab_account_invalid`.
- **Parity fixtures:** `tests/Fixtures/wordpress-live/*.json` are real responses
  from the WordPress site, and the tests rebuild the rows and assert identical
  output. Refresh them from the live site; never edit them to make a test pass.
- **`POST /wp-json/wp/v2/media` is anonymous on purpose.** The app uploads ID
  and insurance photos during sign-up, before it has a token. The protection is
  content-sniffed images only, a size cap, a per-IP limit and a random 40-character
  file name. Do not put the client's file name in the URL.
- **Lab rows are held to the plugin byte for byte** (`PluginParityTest`). After
  changing `LabRows`, re-run it; if the plugin changed, re-record with
  `tools/plugin-golden.php`. Never hand-edit a golden file, and never commit
  vendor-kit data or names: the kit golden stays in `storage/testing`.
- **Signed `/lab-file` links use a key derived from APP_KEY**, so links issued
  by WordPress stop working at cutover. They expire within a day anyway, and the
  app gets fresh ones on its next results load.
- **The poller (`ResultPoller`) keeps the plugin's guards:** first poll per
  (user, lab) only seeds; `ready_signal = none` is never polled; the stored marker
  is `report_datetime` for such labs; the duplicate check includes `lab_id`;
  patients only; one run at a time (cache lock). FCM tokens are pruned on
  `UNREGISTERED`/`NOT_FOUND` only, never `INVALID_ARGUMENT`.
- **Run only one poller across both stacks during migration**, or every alert goes
  out twice (the duplicate check cannot see the other database).
- **Admin access is per page and action** (`App\Support\AdminModules`, one
  registry for the sidebar, the group form and every route's `perm:<page>,<action>`).
  Administrators pass everything; other accounts get their group's ticks
  (`User::canAdmin()`). A **lab group** (`for_labs`) account needs `managed_lab_id`
  or gets nothing, and can never hold a `site_wide` page, whatever is stored.
  An imported `lab_manager` with no group gets `LAB_MANAGER_DEFAULTS`.
  Nobody grants what they lack: `GroupController::fill()` keeps other ticks as
  they were, and `StaffController` only offers groups whose ticks you hold and
  lets only administrators touch administrators.
- **Lab scoping lives in `UserDirectory`**: lists, search, export, `canSee()`
  (which also guards password reset and delete), the dashboard counts and
  `LabMessenger`'s recipients. Admin accounts are excluded from those lists
  (`withoutStaff()`), so a Patients permission cannot reach an administrator.
  Lab accounts never see ID or insurance photos.
- **`LabMessenger` decides the audience from the sender**, not the form: a lab
  account always sends to its own lab. Lab-wide sends write every in-app row
  in one insert and push after the response (`dispatch()->afterResponse()`),
  since one FCM call per device would outrun `max_execution_time`.
- **Accounting**: one `lab_plans` row per lab; recording a payment with
  "advance" moves `next_due_on` one period with `addMonthNoOverflow` (Jan 31 →
  Feb 28). A plan inside `remind_days` of its due date, or past it, shows on
  Accounting, the dashboard and as the sidebar badge. Amounts are USD.
- **Audit log (`AuditTrail`)**: every admin write is one row, written by the
  `audit` middleware (`RecordAdminChanges`) from the Eloquent saves of
  `AuditTrail::WATCHED` models, so a new admin page is logged without code.
  Only requests that went through are logged (no 4xx, no flashed errors).
  App sign-ups, profile changes, password resets, account deletions, admin
  sign-ins and console commands call `record()` themselves. Passwords,
  `encrypted` casts and encrypted settings are stored as "(changed)", never a
  value; `api_token_hash` is not stored at all. Rows snapshot actor and
  subject names and have no foreign keys, so history outlives a deletion.
  Writing a row never throws.
- **`users.created_via`** is `app | admin | console | wordpress`, with
  `created_by_id`/`created_by_name` for admin-made accounts. Null means
  "before tracking": WordPress recorded nothing that tells an app sign-up from
  an admin one, so nothing is guessed.
- **Error log (`ErrorRecorder`)**: every *reported* exception is also upserted
  into `error_logs`, one row per class + file + line with a count; a resolved
  row reopens when it recurs. No request body, no query string, no trace
  arguments. `medvault:prune-logs` runs daily (`config('medvault.logs')`).
