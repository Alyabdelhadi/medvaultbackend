# Deploying MedVault on the medvaultapp.com cPanel

The live WordPress site is `public_html/app` (database `medvaultapp_wp_waifo`,
table prefix `WSwVQWuRS_`). Laravel is installed **next to it**, not instead of
it: a front controller and four rewrite rules hand the app's API, the photo
upload and the admin to Laravel, and WordPress keeps serving everything else,
including `wp-content/uploads`, where existing lab images and patient photos live.

```
https://medvaultapp.com/app/wp-json/custom/v1/*  ─┐
https://medvaultapp.com/app/wp-json/wp/v2/media  ─┼─▶ public_html/app/medvault.php ─▶ ~/medvault-backend
https://medvaultapp.com/app/admin*               ─┘
everything else under /app                         ─▶ WordPress, as today
```

Installed app builds need no update: the URLs do not change.

## A. Install and rehearse (WordPress stays live; nothing changes for users)

**Scripted:** in cPanel → Terminal,

```bash
cd ~ && git clone https://github.com/Alyabdelhadi/medvaultbackend.git medvault-backend && bash ~/medvault-backend/deploy/install.sh
```

does all of this section, including the database and `.env`. The manual steps follow.

1. **Database.** cPanel → *MySQL Database Wizard*: create a database (e.g.
   `medvaultapp_laravel`) and a user with *All Privileges* on it.

2. **Code.** cPanel → *Terminal* (or SSH):
   ```bash
   cd ~ && git clone https://github.com/Alyabdelhadi/medvaultbackend.git medvault-backend
   ```
   The checkout lives in the home directory, **outside `public_html`**. If the
   repository is private, add a read-only deploy key or clone with a token.

3. **Configuration.** `cp .env.example .env`, then set at least:
   ```ini
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://medvaultapp.com/app
   APP_TIMEZONE=Asia/Beirut
   DB_CONNECTION=mysql
   DB_HOST=localhost
   DB_DATABASE=medvaultapp_laravel
   DB_USERNAME=...
   DB_PASSWORD=...
   SESSION_DRIVER=database
   CACHE_STORE=database
   # The live WordPress database on the same server, read by the import only.
   WP_DB_HOST=localhost
   WP_DB_DATABASE=medvaultapp_wp_waifo
   WP_DB_USERNAME=...        # from public_html/app/wp-config.php
   WP_DB_PASSWORD=...
   WP_DB_PREFIX=WSwVQWuRS_
   WP_SITE_URL=https://medvaultapp.com/app
   # New, rotated keys. The old ones are written into functions .php.
   OPENAI_API_KEY=...
   BSB_API_KEY=...
   BSB_API_SECRET=...
   ```

4. **Install.**
   ```bash
   cd ~/medvault-backend
   /usr/local/bin/ea-php84 artisan key:generate   # once, ever; back up .env offline
   /usr/local/bin/ea-php84 $(which composer) install --no-dev --optimize-autoloader
   /usr/local/bin/ea-php84 artisan migrate --force
   /usr/local/bin/ea-php84 artisan medvault:import-wordpress --prefix=WSwVQWuRS_
   cp deploy/medvault.php ~/public_html/app/medvault.php
   ln -s ~/medvault-backend/storage/app/public ~/public_html/app/storage
   /usr/local/bin/ea-php84 artisan storage:link
   /usr/local/bin/ea-php84 artisan medvault:doctor
   ```
   `APP_KEY` encrypts lab tokens, lab API headers and the Firebase key. Lose
   it and those must be re-entered; change it and they become unreadable.

5. **Cron** (cPanel → *Cron Jobs*, every minute):
   ```
   cd ~/medvault-backend && /usr/local/bin/ea-php84 artisan schedule:run >> /dev/null 2>&1
   ```
   Safe now: the import always leaves result polling **off**.

6. **Rehearse** through the front controller directly, before any rewrite:
   - `https://medvaultapp.com/app/medvault.php/wp-json/custom/v1/labs`
   - `https://medvaultapp.com/app/medvault.php/admin` (sign in with your
     WordPress admin username and password)

   Compare a few endpoints with `https://medvaultapp.com/app/wp-json/custom/v1/...`.
   Re-run the import with `--replace` as often as you like until cutover.

## B. Cutover (a quiet hour; about 15 minutes)

**Scripted:** untick polling in WordPress (step 1 below), then run
`bash ~/medvault-backend/deploy/switch.sh`. It does steps 2–7 back to back and puts
the `.htaccess` back by itself if a smoke test fails. `--rollback` undoes it.
Afterwards purge LiteSpeed Cache (WordPress admin → LiteSpeed Cache → Toolbox →
Purge All). The manual steps follow.

Changes users make in WordPress between the final import and step 4 are lost,
so do steps 3–4 back to back.

1. **WordPress admin → Push Notifications → Result Alerts:** untick
   *Polling enabled*, save. From here until step 7 nobody gets result alerts.
2. **Backups:** phpMyAdmin → `medvaultapp_wp_waifo` → Export (keep it off the
   server), and `bash deploy/backup.sh`.
3. **Final import:**
   ```bash
   /usr/local/bin/ea-php84 artisan medvault:import-wordpress --replace --prefix=WSwVQWuRS_ --site-url=https://medvaultapp.com/app
   ```
4. **Switch:** paste `deploy/htaccess-app.conf` into `public_html/app/.htaccess`,
   **above** `# BEGIN WordPress`.
5. **Smoke test:**
   ```bash
   curl -s https://medvaultapp.com/app/up                                # Laravel's health check
   curl -s https://medvaultapp.com/app/wp-json/custom/v1/labs | head -c 200
   ```
   Then sign in with the app on a test account, open results, open a PDF, and
   sign in at `https://medvaultapp.com/app/admin`.
6. `/usr/local/bin/ea-php84 artisan medvault:doctor --cutover` must say *Ready*.
7. **Laravel admin → Result Alerts:** tick *Polling enabled*, then *Run Poll Now*
   once and check the numbers.

## C. After cutover

- **Edit content in the Laravel admin only** (`/app/admin`). WordPress admin
  edits no longer reach the app. WordPress stays installed for
  `wp-content/uploads` and as the way back.
- Rotate the keys that are written into `functions .php` on this server
  (OpenAI, BestSmsBulk) if not done yet.
- Signed PDF links issued by WordPress stop working; they expire within a day
  anyway, and the app gets fresh ones on its next results load.

## Rolling back

See `deploy/htaccess-app-rollback.md`: delete the MedVault block from the
`.htaccess`, switch polling off here and back on in WordPress.

## Updating later

```bash
cd ~/medvault-backend && git pull && bash deploy/backup.sh && bash deploy/deploy.sh
```
