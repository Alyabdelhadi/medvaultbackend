#!/usr/bin/env bash
# Dumps the Laravel database (from .env) to ~/medvault_db_<timestamp>.sql.gz.
# The dump holds patient data: it stays in the home directory, never in
# public_html, and old ones should be deleted.
set -euo pipefail
cd "$(dirname "$0")/.."
get() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
OUT="$HOME/medvault_db_$(date +%Y%m%d_%H%M).sql.gz"
MYSQL_PWD="$(get DB_PASSWORD)" mysqldump --single-transaction --default-character-set=utf8mb4 \
  -h "$(get DB_HOST)" -u "$(get DB_USERNAME)" "$(get DB_DATABASE)" | gzip > "$OUT"
chmod 600 "$OUT"
echo "Backup: $OUT"
