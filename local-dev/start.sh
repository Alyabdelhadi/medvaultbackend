#!/bin/bash
# Runs MedVault locally against a portable PostgreSQL, with nothing installed
# system-wide. Tools live under ~/development/medvault-local (PHP is the static
# build from the CarryOn toolchain; Composer and PostgreSQL 17 sit beside it).
#
#   local-dev/start.sh         start PostgreSQL on 54329 + the PHP server on 8010
#   local-dev/start.sh stop    stop both
#
# Then: DB_CONNECTION=pgsql DB_PORT=54329 DB_DATABASE=medvault DB_USERNAME=postgres
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TOOLS="$HOME/development/medvault-local"
PG="$TOOLS/pgsql/bin"
DATA="$TOOLS/pgdata"
PORT_DB=54329
PORT_WEB=8010
export PATH="$TOOLS/bin:$PATH"

stop() {
  pkill -f "artisan serve --port=$PORT_WEB" 2>/dev/null || true
  "$PG/pg_ctl" -D "$DATA" stop -m fast 2>/dev/null || true
  echo "stopped"
}

if [ "${1:-}" = "stop" ]; then stop; exit 0; fi

[ -d "$DATA" ] || "$PG/initdb" -D "$DATA" -U postgres --auth=trust -E UTF8 >/dev/null
"$PG/pg_ctl" -D "$DATA" status >/dev/null 2>&1 || \
  "$PG/pg_ctl" -D "$DATA" -l "$TOOLS/pg.log" -o "-p $PORT_DB -k $TOOLS" start >/dev/null

php -r '$p = new PDO("pgsql:host=127.0.0.1;port='"$PORT_DB"';dbname=postgres", "postgres", "");
        if (!$p->query("SELECT 1 FROM pg_database WHERE datname = '\''medvault'\''")->fetchColumn()) { $p->exec("CREATE DATABASE medvault"); }'

cd "$ROOT"
php artisan serve --port=$PORT_WEB >"$TOOLS/php-server.log" 2>&1 &
echo "PostgreSQL on $PORT_DB, app on http://127.0.0.1:$PORT_WEB"
