# Sourced by the deploy scripts. Sets $PHP to a command-line PHP 8.3+.
#
# cPanel hosts carry several PHP binaries, and the plain `php` on cron's PATH
# is often php-cgi, which cannot run `php -r` or artisan properly. Each
# candidate is asked for its SAPI and version; only a real CLI 8.3+ is taken.
find_php() {
  local c
  for c in "${PHP:-}" \
    /opt/cpanel/ea-php84/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php \
    /opt/alt/php84/usr/bin/php /opt/alt/php83/usr/bin/php \
    /usr/local/bin/ea-php84 /usr/local/bin/ea-php83 \
    /usr/local/bin/php /usr/bin/php "$(command -v php 2>/dev/null || true)"; do
    [ -n "$c" ] && [ -x "$c" ] || continue
    if [ "$("$c" -r 'echo PHP_SAPI === "cli" && PHP_VERSION_ID >= 80300 ? "ok" : "no";' 2>/dev/null)" = "ok" ]; then
      PHP="$c"
      return 0
    fi
  done
  echo "No command-line PHP 8.3+ found. Candidates tried:" >&2
  for c in /opt/cpanel/ea-php8*/root/usr/bin/php /opt/alt/php8*/usr/bin/php /usr/local/bin/ea-php8* /usr/local/bin/php /usr/bin/php; do
    [ -x "$c" ] && echo "  $c: $("$c" -v 2>&1 | head -1)" >&2
  done
  return 1
}
