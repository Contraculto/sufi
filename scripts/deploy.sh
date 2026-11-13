#!/usr/bin/env bash
# Publish SUFI to contraculto.com (alongside the corporate site).
#
#   ./scripts/deploy.sh --dry-run    # show what would change
#   ./scripts/deploy.sh              # run tests, upload, verify
#   ./scripts/deploy.sh --quick      # skip tests (hotfix)
#
# Only application files are uploaded. Runtime state lives in sys/ on the
# server and is never touched: config.php, *.pem, sufi.sqlite, logs/.
#
# Rollback:
#   ssh root@contraculto.com 'cd /var/www/contraculto/sufi && cp -a index.php.bak-<stamp> index.php'

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HOST="${CONTRACULTO_DEPLOY_HOST:-contraculto.com}"
REMOTE_DIR="${SUFI_REMOTE_DIR:-/var/www/contraculto/sufi}"
STAMP="$(date +%Y%m%d-%H%M)"
DRY=0
QUICK=0

for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY=1 ;;
        --quick)   QUICK=1 ;;
        *) echo "usage: $0 [--dry-run] [--quick]" >&2; exit 2 ;;
    esac
done

log() { echo "[$(date '+%H:%M:%S')] $*"; }
die() { echo "⛔ $*" >&2; exit 1; }

for f in index.php .htaccess templates sys/database.php sys/sufi.sql deploy; do
    [[ -e "$ROOT/$f" ]] || die "missing $f"
done
log "local files OK"

if [[ $QUICK -eq 0 && $DRY -eq 0 ]]; then
    log "running tests"
    "$ROOT/scripts/test.sh" || die "tests failed — fix them or use --quick"
fi

log "checking https://$HOST"
curl -fsS -o /dev/null "https://$HOST/" || die "site is not responding"

if [[ $DRY -eq 1 ]]; then
    log "DRY-RUN — changes that would be uploaded to $REMOTE_DIR"
    rsync -navc --delete \
        --exclude 'sys/' --exclude '.git/' --exclude 'tests/' --exclude 'scripts/' \
        --exclude '.github/' --exclude '*~' \
        "$ROOT/" "root@$HOST:$REMOTE_DIR/"
    rsync -navc "$ROOT/sys/database.php" "$ROOT/sys/sufi.sql" "$ROOT/sys/delivery.php" "root@$HOST:$REMOTE_DIR/sys/"
    rsync -navc "$ROOT/scripts/ops/deliver.php" "root@$HOST:$REMOTE_DIR/scripts/ops/"
    rsync -navc "$ROOT/scripts/ops/backup.sh" "$ROOT/scripts/ops/restore.sh" "$ROOT/scripts/ops/prune.php" "$ROOT/scripts/ops/backfill.php" "root@$HOST:$REMOTE_DIR/scripts/ops/"
    echo
    log "DRY-RUN — runtime files stay untouched:"
    echo "  sys/config.php  sys/private.pem  sys/public.pem  sys/sufi.sqlite  sys/logs/"
    echo
    log "DRY-RUN — cron entries:"
    echo "  * * * * * www-data php $REMOTE_DIR/scripts/ops/deliver.php"
    echo "  17 4 * * * www-data bash $REMOTE_DIR/scripts/ops/backup.sh"
    echo "  27 4 * * * www-data php $REMOTE_DIR/scripts/ops/prune.php"
    exit 0
fi

log "backing up remote code"
ssh "root@$HOST" "cd $REMOTE_DIR && cp -a index.php index.php.bak-$STAMP && cp -a templates templates.bak-$STAMP"

log "uploading application files"
rsync -a --delete \
    --exclude 'sys/' --exclude '.git/' --exclude 'tests/' --exclude 'scripts/' \
    --exclude '.github/' --exclude '*~' \
    "$ROOT/" "root@$HOST:$REMOTE_DIR/"
rsync -a "$ROOT/sys/database.php" "$ROOT/sys/sufi.sql" "$ROOT/sys/delivery.php" "root@$HOST:$REMOTE_DIR/sys/"
ssh "root@$HOST" "mkdir -p $REMOTE_DIR/scripts/ops"
rsync -a "$ROOT/scripts/ops/deliver.php" "root@$HOST:$REMOTE_DIR/scripts/ops/"
rsync -a "$ROOT/scripts/ops/backup.sh" "$ROOT/scripts/ops/restore.sh" "$ROOT/scripts/ops/prune.php" "$ROOT/scripts/ops/backfill.php" "root@$HOST:$REMOTE_DIR/scripts/ops/"

log "linting on the server"
ssh "root@$HOST" "php -l $REMOTE_DIR/index.php > /dev/null \
    && php -l $REMOTE_DIR/sys/delivery.php > /dev/null \
    && php -l $REMOTE_DIR/scripts/ops/deliver.php > /dev/null \\
    && php -l $REMOTE_DIR/scripts/ops/prune.php > /dev/null \\
    && php -l $REMOTE_DIR/scripts/ops/backfill.php > /dev/null \\
    && bash -n $REMOTE_DIR/scripts/ops/backup.sh \\
    && bash -n $REMOTE_DIR/scripts/ops/restore.sh" \
    || die "syntax error or missing file on server"

log "installing cron jobs"
ssh "root@$HOST" "printf '# SUFI: deliveries every minute, daily backup and inbox pruning\\n* * * * * www-data php $REMOTE_DIR/scripts/ops/deliver.php >/dev/null 2>&1\\n17 4 * * * www-data bash $REMOTE_DIR/scripts/ops/backup.sh >/dev/null 2>&1\\n27 4 * * * www-data php $REMOTE_DIR/scripts/ops/prune.php >/dev/null 2>&1\\n' > /etc/cron.d/sufi && chmod 644 /etc/cron.d/sufi && cat /etc/cron.d/sufi"
ssh "root@$HOST" "rm -f /etc/cron.d/sufi-deliver"

log "checking federation endpoints"
for path in "/.well-known/webfinger" "/ap/actor" "/ap/outbox"; do
    code=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/activity+json' "https://$HOST$path")
    log "  $path → $code"
done

HANDLE=$(ssh "root@$HOST" "php -r 'include \"$REMOTE_DIR/sys/config.php\"; echo \$username;'")
curl -fsS "https://$HOST/.well-known/webfinger?resource=acct:$HANDLE@$HOST" | grep -q '"subject"' \
    || die "webfinger for @$HANDLE@$HOST is broken"
log "  webfinger for @$HANDLE@$HOST OK"

code=$(curl -s -o /dev/null -w '%{http_code}' "https://$HOST/sufi/")
[[ "$code" == "200" ]] || die "panel returned $code"
log "  panel /sufi/ → 200"

log "recent security log entries (if any)"
ssh "root@$HOST" "test -f $REMOTE_DIR/sys/logs/security.log && tail -n 5 $REMOTE_DIR/sys/logs/security.log || echo '  none'"

log "✅ deployed"
echo "Rollback if needed:"
echo "  ssh root@$HOST 'cd $REMOTE_DIR && cp -a index.php.bak-$STAMP index.php && rm -rf templates && cp -a templates.bak-$STAMP templates'"
