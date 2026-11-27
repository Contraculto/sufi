#!/usr/bin/env bash
# Back up the SQLite database into sys/backups/ and keep the last N copies.
# Installed as a daily cron job by scripts/deploy.sh.
#
#   ./scripts/ops/backup.sh
#   SUFI_BACKUP_KEEP=30 ./scripts/ops/backup.sh
#
# Restore: stop writes (or put the site in maintenance), then
#   cp sys/backups/sufi-<stamp>.sqlite sys/sufi.sqlite && rm -f sys/sufi.sqlite-wal sys/sufi.sqlite-shm

set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
db="${SUFI_DB:-$root/sys/sufi.sqlite}"
dir="${SUFI_BACKUP_DIR:-$root/sys/backups}"
keep="${SUFI_BACKUP_KEEP:-14}"
php="${PHP:-php}"

[[ -f "$db" ]] || { echo "no database at $db" >&2; exit 1; }

mkdir -p "$dir"
chmod 750 "$dir" 2>/dev/null || true

out="$dir/sufi-$(date +%Y%m%d-%H%M).sqlite"

# VACUUM INTO is safe to run while the site is live (WAL).
"$php" -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec("VACUUM INTO " . $p->quote($argv[2]));' \
    "$db" "$out"
chmod 640 "$out"

integrity=$("$php" -r '$p = new PDO("sqlite:" . $argv[1]); echo (string) $p->query("PRAGMA integrity_check")->fetchColumn();' "$out")
if [[ "$integrity" != "ok" ]]; then
    echo "backup integrity check failed: $integrity" >&2
    rm -f "$out"
    exit 1
fi

ls -1t "$dir"/sufi-*.sqlite 2>/dev/null | tail -n +$((keep + 1)) | xargs -r rm -f

echo "backup: $out ($(du -h "$out" | cut -f1), integrity ok)"
