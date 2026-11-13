#!/usr/bin/env bash
# Publish SUFI to contraculto.com (alongside the corporate site).
#
#   ./scripts/deploy.sh --check      # verify the file manifest + syntax, offline
#   ./scripts/deploy.sh --dry-run    # show what would change on the server
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
PHP_BIN="${PHP:-php}"
DRY=0
QUICK=0
CHECK=0

# Everything that must reach the server. A file missing from these lists is a
# file that silently does not get deployed — the two outages so far came from
# exactly that, so --check validates the lists and deploy.sh refuses to start
# if something is missing.
APP_FILES=(index.php .htaccess README.md LICENSE AGENTS.md templates deploy)
SYS_FILES=(sys/database.php sys/sufi.sql sys/delivery.php)
OPS_FILES=(deliver.php health.php backup.sh restore.sh prune.php backfill.php)

for arg in "$@"; do
    case "$arg" in
        --check)   CHECK=1 ;;
        --dry-run) DRY=1 ;;
        --quick)   QUICK=1 ;;
        *) echo "usage: $0 [--check] [--dry-run] [--quick]" >&2; exit 2 ;;
    esac
done

log() { echo "[$(date '+%H:%M:%S')] $*"; }
die() { echo "⛔ $*" >&2; exit 1; }

manifest_check() {
    for f in "${APP_FILES[@]}" "${SYS_FILES[@]}"; do
        [[ -e "$ROOT/$f" ]] || die "missing $f"
    done
    for f in "${OPS_FILES[@]}"; do
        [[ -e "$ROOT/scripts/ops/$f" ]] || die "missing scripts/ops/$f"
    done

    find "$ROOT" -name '*.php' -not -path '*/.git/*' -print0 | xargs -0 -n1 "$PHP_BIN" -l > /dev/null
    for f in "$ROOT"/scripts/ops/*.sh; do bash -n "$f"; done
}

manifest_check

if [[ $CHECK -eq 1 ]]; then
    log "manifest OK: ${#APP_FILES[@]} app, ${#SYS_FILES[@]} sys, ${#OPS_FILES[@]} ops files"
    exit 0
fi

log "local files OK"

if [[ $QUICK -eq 0 && $DRY -eq 0 ]]; then
    log "running tests"
    "$ROOT/scripts/test.sh" || die "tests failed — fix them or use --quick"
fi

log "checking https://$HOST"
curl -fsS -o /dev/null "https://$HOST/" || die "site is not responding"

sys_paths=()
for f in "${SYS_FILES[@]}"; do sys_paths+=("$ROOT/$f"); done
ops_paths=()
for f in "${OPS_FILES[@]}"; do ops_paths+=("$ROOT/scripts/ops/$f"); done

if [[ $DRY -eq 1 ]]; then
    log "DRY-RUN — changes that would be uploaded to $REMOTE_DIR"
    rsync -navc --delete \
        --exclude 'sys/' --exclude '.git/' --exclude 'tests/' --exclude 'scripts/' \
        --exclude '.github/' --exclude '*~' \
        "$ROOT/" "root@$HOST:$REMOTE_DIR/"
    rsync -navc "${sys_paths[@]}" "root@$HOST:$REMOTE_DIR/sys/"
    rsync -navc "${ops_paths[@]}" "root@$HOST:$REMOTE_DIR/scripts/ops/"
    echo
    log "DRY-RUN — runtime files stay untouched:"
    echo "  sys/config.php  sys/private.pem  sys/public.pem  sys/sufi.sqlite  sys/logs/"
    echo
    log "DRY-RUN — cron entries:"
    echo "  */15 * * * * www-data php $REMOTE_DIR/scripts/ops/health.php"
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
ssh "root@$HOST" "mkdir -p $REMOTE_DIR/scripts/ops"
rsync -a "${sys_paths[@]}" "root@$HOST:$REMOTE_DIR/sys/"
rsync -a "${ops_paths[@]}" "root@$HOST:$REMOTE_DIR/scripts/ops/"

log "linting on the server"
remote_cmd="php -l $REMOTE_DIR/index.php > /dev/null"
for f in "${SYS_FILES[@]}"; do
    case "$f" in
        *.php) remote_cmd+=" && php -l $REMOTE_DIR/$f > /dev/null" ;;
    esac
done
for f in "${OPS_FILES[@]}"; do
    case "$f" in
        *.php) remote_cmd+=" && php -l $REMOTE_DIR/scripts/ops/$f > /dev/null" ;;
        *.sh)  remote_cmd+=" && bash -n $REMOTE_DIR/scripts/ops/$f" ;;
    esac
done
ssh "root@$HOST" "$remote_cmd" || die "syntax error or missing file on server"

log "installing cron jobs"
cron_body="# SUFI: health, deliveries, daily backup and inbox pruning\\n"
cron_body+="*/15 * * * * www-data php $REMOTE_DIR/scripts/ops/health.php >/dev/null 2>&1\\n"
cron_body+="* * * * * www-data php $REMOTE_DIR/scripts/ops/deliver.php >/dev/null 2>&1\\n"
cron_body+="17 4 * * * www-data bash $REMOTE_DIR/scripts/ops/backup.sh >/dev/null 2>&1\\n"
cron_body+="27 4 * * * www-data php $REMOTE_DIR/scripts/ops/prune.php >/dev/null 2>&1\\n"
ssh "root@$HOST" "printf '$cron_body' > /etc/cron.d/sufi && chmod 644 /etc/cron.d/sufi && cat /etc/cron.d/sufi"
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

log "health check"
ssh "root@$HOST" "cd $REMOTE_DIR && sudo -u www-data php scripts/ops/health.php" | sed 's/^/  /' \
    || log "  health reported problems — see above"

log "recent security log entries (if any)"
ssh "root@$HOST" "test -f $REMOTE_DIR/sys/logs/security.log && tail -n 5 $REMOTE_DIR/sys/logs/security.log || echo '  none'"

log "✅ deployed"
echo "Rollback if needed:"
echo "  ssh root@$HOST 'cd $REMOTE_DIR && cp -a index.php.bak-$STAMP index.php && rm -rf templates && cp -a templates.bak-$STAMP templates'"
