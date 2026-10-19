#!/usr/bin/env bash
# Phase 1 of docs/DEPLOY.md in one go, on the medvaultapp.com cPanel host:
#
#   cd ~ && git clone https://github.com/Alyabdelhadi/medvaultbackend.git medvault-backend \
#     && bash ~/medvault-backend/deploy/install.sh
#
# Installs Laravel BESIDE WordPress. Nothing users see changes: .htaccess and
# WordPress are not touched, and result polling stays off. The switch itself
# is phase 2 (deploy/htaccess-app.conf), done separately.
#
# Secrets never pass through a screen: the database password is generated
# here, and the WordPress database login and the OpenAI / BestSmsBulk keys are
# copied from wp-config.php and the theme's functions.php into .env without
# being printed. Safe to run again; existing values are kept.
set -euo pipefail

APP="$HOME/medvault-backend"
WP="${WP:-$HOME/public_html/app}"
REPO="https://github.com/Alyabdelhadi/medvaultbackend.git"
step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

step "Checking the server"
. "$(dirname "$0")/find-php.sh"
find_php || fail "Set PHP=/path/to/php (a CLI PHP 8.3+) and rerun."
echo "PHP: $PHP ($("$PHP" -r 'echo PHP_VERSION;'))"
for ext in pdo_mysql mbstring openssl fileinfo curl xml tokenizer ctype; do
  "$PHP" -r "exit(extension_loaded('$ext') ? 0 : 1);" || fail "PHP extension $ext is off. Enable it in cPanel → Select PHP Version, then rerun."
done
[ -f "$WP/wp-config.php" ] || fail "WordPress not found at $WP. Rerun with WP=/path/to/wordpress."
command -v git >/dev/null || fail "git is not available."
command -v uapi >/dev/null || fail "cPanel's uapi command is not available; create the database by hand (docs/DEPLOY.md, A.1)."
CPUSER="$(whoami)"

step "Code"
if [ -d "$APP/.git" ]; then git -C "$APP" pull --ff-only; else git clone "$REPO" "$APP"; fi
cd "$APP"

step "Composer"
# Many hosts turn allow_url_fopen off for the CLI. Composer needs it; the app
# does not (its HTTP goes through curl), so it is switched on for Composer only.
COMPOSER_PHP=("$PHP" -d allow_url_fopen=1 -d memory_limit=-1)
# Always our own signature-checked composer.phar, run with the chosen PHP: a host's
# composer may be a shell wrapper, or run under a different PHP version.
if [ ! -f "$APP/composer.phar" ]; then
  EXPECTED="$(curl -fsSL https://composer.github.io/installer.sig)"
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup-$$.php
  [ "$("$PHP" -r "echo hash_file('sha384', '/tmp/composer-setup-$$.php');")" = "$EXPECTED" ] || fail "Composer installer signature mismatch."
  "${COMPOSER_PHP[@]}" /tmp/composer-setup-$$.php --quiet --install-dir="$APP" --filename=composer.phar
  rm -f /tmp/composer-setup-$$.php
fi
"${COMPOSER_PHP[@]}" "$APP/composer.phar" install --no-dev --optimize-autoloader --no-interaction --no-progress

step "Configuration (.env)"
if [ ! -f .env ]; then
  cp .env.example .env
  chmod 600 .env
  CREATED_ENV=1
else
  CREATED_ENV=0
  echo ".env exists; only empty values are filled in."
fi

# Production values. On a fresh .env they replace the .env.example defaults
# (which are SQLite and localhost); on a re-run, existing values are kept.
# Values come in on stdin so they never appear in the process list.
DB_HOST="${DB_HOST:-localhost}" DB_PORT="${DB_PORT:-3306}" WP_DB_HOST="${WP_DB_HOST:-}" WP_DB_PORT="${WP_DB_PORT:-3306}" \
"$PHP" -r '
  $force = $argv[1] === "1";
  $file = ".env"; $env = file_get_contents($file);
  $pairs = json_decode(stream_get_contents(STDIN), true) ?: [];
  $pairs += ["DB_HOST" => getenv("DB_HOST"), "DB_PORT" => getenv("DB_PORT"), "WP_DB_PORT" => getenv("WP_DB_PORT")];
  if (getenv("WP_DB_HOST") !== "") { $pairs["WP_DB_HOST"] = getenv("WP_DB_HOST"); }
  foreach ($pairs as $key => $value) {
    $line = $key."=".(preg_match("/[\s#\"]/", (string) $value) ? "\"".addcslashes((string) $value, "\"\\")."\"" : $value);
    if (preg_match("/^".preg_quote($key)."=(.*)$/m", $env, $m)) {
      if ($force || trim($m[1], " \"") === "") { $env = preg_replace("/^".preg_quote($key)."=.*$/m", addcslashes($line, "\\$"), $env); }
    } else { $env .= "\n".$line; }
  }
  file_put_contents($file, $env);
' "$CREATED_ENV" <<'JSON'
{"APP_ENV":"production","APP_DEBUG":"false","APP_URL":"https://medvaultapp.com/app","APP_TIMEZONE":"Asia/Beirut",
 "DB_CONNECTION":"mysql","SESSION_DRIVER":"database","CACHE_STORE":"database",
 "QUEUE_CONNECTION":"sync","LOG_LEVEL":"warning","WP_SITE_URL":"https://medvaultapp.com/app","WP_DB_DRIVER":"mysql"}
JSON
# .env.example ships the DB_* lines commented out; drop those so the real ones win.
"$PHP" -r 'file_put_contents(".env", preg_replace("/^# *DB_(HOST|PORT|DATABASE|USERNAME|PASSWORD)=.*\n/m", "", file_get_contents(".env")));'

step "WordPress database login and current API keys (copied, not shown)"
# Read as text and matched with patterns: wp-config.php and functions.php are
# never executed here.
THEME_FUNCTIONS="$( (grep -rl --include=functions.php "medvault_lab_drivers" "$WP/wp-content/themes" 2>/dev/null || true) | sed -n 1p )"
"$PHP" -r '
  [$_, $config, $functions] = $argv;
  $c = file_get_contents($config);
  $const = fn ($name) => preg_match("/define\(\s*[\x27\"]".$name."[\x27\"]\s*,\s*[\x27\"](.*?)[\x27\"]\s*\)/", $c, $m) ? stripcslashes($m[1]) : "";
  preg_match("/\\\$table_prefix\s*=\s*[\x27\"](.*?)[\x27\"]/", $c, $p);
  $out = ["WP_DB_HOST" => getenv("WP_DB_HOST") ?: ($const("DB_HOST") ?: "localhost"), "WP_DB_DATABASE" => $const("DB_NAME"), "WP_DB_USERNAME" => $const("DB_USER"), "WP_DB_PASSWORD" => $const("DB_PASSWORD"), "WP_DB_PREFIX" => $p[1] ?? "wp_"];
  if ($functions !== "" && is_file($functions)) {
    $f = file_get_contents($functions);
    if (preg_match("/\\\$openai_key\s*=\s*[\x27\"](sk-[^\x27\"]+)[\x27\"]/", $f, $m)) { $out["OPENAI_API_KEY"] = $m[1]; }
    if (preg_match("/[\x27\"]api_key[\x27\"]\s*=>\s*[\x27\"]([0-9a-f]{20,})[\x27\"]/", $f, $m)) { $out["BSB_API_KEY"] = $m[1]; }
    if (preg_match("/[\x27\"]api_secret[\x27\"]\s*=>\s*[\x27\"]([0-9a-f]{20,})[\x27\"]/", $f, $m)) { $out["BSB_API_SECRET"] = $m[1]; }
  }
  foreach ($out as $k => $v) { fwrite(STDERR, str_pad($k, 16).($v === "" ? "not found" : ($k === "WP_DB_DATABASE" || $k === "WP_DB_PREFIX" || $k === "WP_DB_HOST" ? $v : "copied"))."\n"); }
  echo json_encode($out);
' "$WP/wp-config.php" "$THEME_FUNCTIONS" > /tmp/mv-env-$$.json
"$PHP" -r '
  $env = file_get_contents(".env");
  foreach (json_decode(file_get_contents($argv[1]), true) as $key => $value) {
    if ($value === "") { continue; }
    $isWp = str_starts_with($key, "WP_DB_");
    $line = $key."=".(preg_match("/[\s#\"\$]/", $value) ? "\"".addcslashes($value, "\"\\\$")."\"" : $value);
    if (preg_match("/^".preg_quote($key)."=(.*)$/m", $env, $m)) {
      if ($isWp || trim($m[1], " \"") === "") { $env = preg_replace("/^".preg_quote($key)."=.*$/m", addcslashes($line, "\\$"), $env); }
    } else { $env .= "\n".$line; }
  }
  file_put_contents(".env", $env);
' /tmp/mv-env-$$.json
rm -f /tmp/mv-env-$$.json

step "Laravel database (${CPUSER}_laravel)"
DB_NAME="${CPUSER}_laravel"
DB_USER="${CPUSER}_laravel"
if grep -qE "^DB_PASSWORD=.+$" .env && grep -qE "^DB_DATABASE=${DB_NAME}$" .env; then
  echo "Already configured."
else
  ok() { case "$1" in *'"status":1'*) return 0;; *) return 1;; esac; }
  if ok "$(uapi --output=json Mysql create_database name="$DB_NAME")"; then echo "Database created."; else echo "Database exists or could not be created; continuing."; fi
  # Not `tr < /dev/urandom | head`: under pipefail that pipeline dies of SIGPIPE.
  DB_PASS="$("$PHP" -r 'echo bin2hex(random_bytes(16));')"
  if ok "$(uapi --output=json Mysql create_user name="$DB_USER" password="$DB_PASS")"; then
    echo "Database user created."
  else
    ok "$(uapi --output=json Mysql set_password user="$DB_USER" password="$DB_PASS")" || fail "Could not create or update the database user $DB_USER."
    echo "Database user existed; password reset."
  fi
  ok "$(uapi --output=json Mysql set_privileges_on_database user="$DB_USER" database="$DB_NAME" privileges="ALL PRIVILEGES")" || fail "Could not grant privileges."
  printf '%s' "{\"DB_DATABASE\":\"$DB_NAME\",\"DB_USERNAME\":\"$DB_USER\",\"DB_PASSWORD\":\"$DB_PASS\"}" | "$PHP" -r '
    $env = file_get_contents(".env");
    foreach (json_decode(stream_get_contents(STDIN), true) as $k => $v) {
      $env = preg_match("/^$k=.*$/m", $env) ? preg_replace("/^$k=.*$/m", "$k=$v", $env) : $env."\n$k=$v";
    }
    file_put_contents(".env", $env);'
  unset DB_PASS
  echo "Login written to .env."
fi
chmod 600 .env

step "Application key"
grep -qE "^APP_KEY=base64:.+" .env && echo "Kept the existing key." || "$PHP" artisan key:generate --force
echo "Back up ~/medvault-backend/.env somewhere safe and offline: APP_KEY decrypts lab tokens and credentials."

step "Database schema"
"$PHP" artisan config:clear >/dev/null
"$PHP" artisan migrate --force

step "Importing from WordPress (read-only on the WordPress side)"
"$PHP" artisan medvault:import-wordpress --if-empty --site-url=https://medvaultapp.com/app

step "Public pieces under $WP (no effect until the .htaccess switch)"
install -m 0644 deploy/medvault.php "$WP/medvault.php"
[ -e "$WP/storage" ] || ln -s "$APP/storage/app/public" "$WP/storage"
"$PHP" artisan storage:link >/dev/null 2>&1 || true
"$PHP" artisan config:cache >/dev/null
"$PHP" artisan route:cache >/dev/null
"$PHP" artisan view:cache >/dev/null

step "Cron (scheduler; result polling itself stays OFF)"
CRON="* * * * * cd $APP && $PHP artisan schedule:run >> /dev/null 2>&1"
if crontab -l 2>/dev/null | grep -F "$APP && $PHP artisan schedule:run" >/dev/null; then
  echo "Already installed."
else
  ( crontab -l 2>/dev/null; echo "$CRON" ) | crontab - && echo "Installed: $CRON"
fi

step "Health check"
"$PHP" artisan medvault:doctor || true
touch "$HOME/medvault-install.done"

cat <<EOF

Done. Nothing has changed for users yet.

Try it through the front controller:
  https://medvaultapp.com/app/medvault.php/wp-json/custom/v1/labs
  https://medvaultapp.com/app/medvault.php/admin   (your WordPress admin login)

The switch (phase 2) is: polling off in WordPress, a final
  $PHP artisan medvault:import-wordpress --replace --site-url=https://medvaultapp.com/app
then deploy/htaccess-app.conf into $WP/.htaccess above "# BEGIN WordPress",
then polling on in the Laravel admin.
EOF
