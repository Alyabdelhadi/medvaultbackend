#!/usr/bin/env bash
# Phase 2 of docs/DEPLOY.md: the switch. Run on the cPanel host after
# deploy/install.sh, and only after turning result polling OFF in WordPress
# (WordPress admin → Push Notifications → Result Alerts):
#
#   bash ~/medvault-backend/deploy/switch.sh
#
# Back to back, so nothing users change in between is lost: a final import,
# the routing block into public_html/app/.htaccess, smoke tests against the
# live URLs, and polling on here. If a smoke test fails the .htaccess is put
# back at once and WordPress serves everything again.
#
#   bash ~/medvault-backend/deploy/switch.sh --rollback
#
# removes the routing block (and turns polling off here); turn polling back
# on in WordPress afterwards.
set -euo pipefail

APP="$HOME/medvault-backend"
WP="${WP:-$HOME/public_html/app}"
SITE="${SITE:-https://medvaultapp.com/app}"
HTACCESS="$WP/.htaccess"
BEGIN="# BEGIN MedVault Laravel"
END="# END MedVault Laravel"
step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }
. "$(dirname "$0")/find-php.sh"
find_php || fail "Set PHP=/path/to/php (a CLI PHP 8.3+) and rerun."
cd "$APP"

remove_block() {
  "$PHP" -r '
    [$_, $file, $begin, $end] = $argv;
    $s = file_get_contents($file);
    $s = preg_replace("/".preg_quote($begin, "/").".*?".preg_quote($end, "/")."\n{0,2}/s", "", $s);
    file_put_contents($file, $s);
  ' "$HTACCESS" "$BEGIN" "$END"
}
polling() { "$PHP" artisan tinker --execute="App\\Models\\Setting::put('poll_enabled', $1);" >/dev/null; }

if [ "${1:-}" = "--rollback" ]; then
  step "Rolling back"
  cp "$HTACCESS" "$HOME/htaccess-app-$(date +%Y%m%d_%H%M%S).bak"
  remove_block
  polling false
  echo "WordPress serves everything again, and polling is off here."
  echo "Now turn polling back ON in WordPress: Push Notifications → Result Alerts."
  exit 0
fi

step "Preconditions"
[ -f "$WP/medvault.php" ] || fail "Run deploy/install.sh first."
"$PHP" artisan medvault:doctor >/dev/null || fail "medvault:doctor reports problems. Run it to see them."
# Two pollers send every alert twice. WordPress's own setting decides.
WP_POLLING="$("$PHP" artisan tinker --execute='echo (int) (DB::connection("wordpress")->table(env("WP_DB_PREFIX", "wp_")."options")->where("option_name", "medvault_poll_enabled")->value("option_value") ?? 1);' 2>/dev/null | tail -1)"
[ "$WP_POLLING" = "0" ] || fail "Result polling is still ON in WordPress. Untick it (Push Notifications → Result Alerts → Save), then rerun."
echo "WordPress polling is off. Patients get no result alerts until the last step."
if grep -qF "$BEGIN" "$HTACCESS"; then
  fail "The routing block is already in $HTACCESS. To redo the switch: bash deploy/switch.sh --rollback first."
fi

step "Backups"
bash deploy/backup.sh
cp "$HTACCESS" "$HOME/htaccess-app-$(date +%Y%m%d_%H%M%S).bak"
echo "Saved $HTACCESS to ~/htaccess-app-*.bak"

step "Final import (replaces the rehearsal data)"
"$PHP" artisan medvault:import-wordpress --replace --site-url="$SITE"
"$PHP" artisan config:cache >/dev/null

step "Routing the app to Laravel"
"$PHP" -r '
  [$_, $file, $blockFile] = $argv;
  $s = file_get_contents($file); $block = rtrim(file_get_contents($blockFile))."\n\n";
  $pos = strpos($s, "# BEGIN WordPress");
  file_put_contents($file, $pos === false ? $block.$s : substr($s, 0, $pos).$block.substr($s, $pos));
' "$HTACCESS" deploy/htaccess-app.conf
echo "Inserted above # BEGIN WordPress."

step "Smoke tests against $SITE"
ok=1
check() { # url, expected status, text that must appear
  local body code
  body="$(curl -s -m 30 -w '\n%{http_code}' "$1" || true)"
  code="${body##*$'\n'}"; body="${body%$'\n'*}"
  if [ "$code" = "$2" ] && [[ "$body" == *"$3"* ]]; then echo "OK    $code $1"; else echo "FAIL  $code $1"; ok=0; fi
}
check "$SITE/up" 200 "Application up"
check "$SITE/wp-json/custom/v1/phone" 200 '"phone"'
check "$SITE/wp-json/custom/v1/labs" 200 '"success":true'
check "$SITE/wp-json/custom/v1/profile" 401 '"code":"unauthorized"'
check "$SITE/admin/login" 200 "MedVault"
if [ "$ok" != 1 ]; then
  remove_block
  fail "Smoke tests failed; the .htaccess is restored and WordPress serves everything. Nothing else changed for users."
fi

step "Result polling on here"
polling true
"$PHP" artisan medvault:poll-results --force || true

cat <<EOF

Switched. The app is served by Laravel; WordPress still serves wp-content/uploads.

Now:
  1. LiteSpeed Cache (WordPress admin → LiteSpeed Cache → Toolbox) → Purge All,
     so no response WordPress cached earlier is served for the app's URLs.
  2. On a phone: sign in with a test account, open results, open a PDF.
Admin: $SITE/admin (your WordPress admin login).
Edit content only there from now on.

If anything is wrong: bash ~/medvault-backend/deploy/switch.sh --rollback
EOF
