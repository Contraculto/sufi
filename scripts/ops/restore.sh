#!/usr/bin/env bash
# Restore a SUFI database from a backup.
#
#   sudo systemctl stop php8.4-fpm          # so nothing writes during the swap
#   ./scripts/ops/restore.sh sys/backups/sufi-20261113-0417.sqlite --yes
#   sudo systemctl start php8.4-fpm
#
# The current database is kept as sys/sufi.sqlite.pre-restore-<stamp>.
# Refuses to run without --yes and verifies the backup before copying it.

set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
db="${SUFI_DB:-$root/sys/sufi.sqlite}"
php="${PHP:-php}"
src=""
confirmed=0

for arg in "$@"; do
    case "$arg" in
        --yes) confirmed=1 ;;
        -*) echo "usage: $0 <backup.sqlite> --yes" >&2; exit 2 ;;
        *) src="$arg" ;;
    esac
done

if [[ -z "$src" || $confirmed -eq 0 ]]; then
    echo "usage: $0 <backup.sqlite> --yes" >&2
    echo "stop php-fpm first: sudo systemctl stop php8.4-fpm" >&2
    exit 2
fi

[[ -f "$src" ]] || { echo "no such backup: $src" >&2; exit 1; }

integrity=$("$php" -r '$p = new PDO("sqlite:" . $argv[1]); echo (string) $p->query("PRAGMA integrity_check")->fetchColumn();' "$src")
if [[ "$integrity" != "ok" ]]; then
    echo "backup failed integrity check: $integrity" >&2
    exit 1
fi

stamp="$(date +%Y%m%d-%H%M)"
if [[ -f "$db" ]]; then
    cp -a "$db" "$db.pre-restore-$stamp"
    echo "current database kept at $db.pre-restore-$stamp"
fi

cp -a "$src" "$db"
rm -f "$db-wal" "$db-shm"

if [[ ${EUID:-$(id -u)} -eq 0 ]]; then
    chown www-data:www-data "$db"
    chmod 640 "$db"
fi

echo "restored $src → $db (integrity ok)"
echo "start the site again: sudo systemctl start php8.4-fpm"
