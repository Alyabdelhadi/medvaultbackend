#!/usr/bin/env bash
# Deploys ~/medvault-backend on the cPanel host. Run there over SSH, or paste
# the steps into cPanel → Terminal:
#
#   cd ~/medvault-backend && git pull && bash deploy/deploy.sh
#
# Refuses to run without a database backup from the last two hours.
set -euo pipefail

APP="$(cd "$(dirname "$0")/.." && pwd)"
PHP="${PHP:-/usr/local/bin/ea-php84}"
WEB="${WEB:-$HOME/public_html/app}"
cd "$APP"

[ -x "$PHP" ] || PHP=php
"$PHP" -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' || { echo "PHP 8.3+ needed; rerun with PHP=/usr/local/bin/ea-php84" >&2; exit 1; }
[ -f .env ] || { echo "No .env. Copy .env.example, fill it in, then: $PHP artisan key:generate" >&2; exit 1; }

if [ -z "$(find "$HOME" -maxdepth 1 -name 'medvault_db_*.sql*' -size +1k -mmin -120 2>/dev/null)" ]; then
  echo "No fresh backup (~/medvault_db_*.sql). Run: bash deploy/backup.sh" >&2
  exit 1
fi

COMPOSER="${COMPOSER:-composer}"
"$PHP" "$(command -v "$COMPOSER")" install --no-dev --optimize-autoloader --no-interaction

"$PHP" artisan down --retry=30 || true
"$PHP" artisan migrate --force
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache
"$PHP" artisan storage:link || true

# The public pieces under public_html/app: the front controller and uploads.
install -m 0644 deploy/medvault.php "$WEB/medvault.php"
[ -e "$WEB/storage" ] || ln -s "$APP/storage/app/public" "$WEB/storage"

"$PHP" artisan up
"$PHP" artisan medvault:doctor || true
echo "Deployed $(git rev-parse --short HEAD)."
