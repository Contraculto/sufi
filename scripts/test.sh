#!/usr/bin/env bash
# SUFI test suite. Needs only PHP, OpenSSL and Bash — no composer, no curl.
#
#   ./scripts/test.sh
#
# It builds two throwaway instances in a temp dir (one at the domain root, one
# under /sufi), starts a fake remote server, runs tests/run.php against them and
# deletes everything afterwards. Nothing touches your real sys/ directory.

set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
php="${PHP:-php}"
port="${SUFI_TEST_PORT:-8191}"
port_prefix=$((port + 1))
port_remote=$((port + 2))
sandbox="$(mktemp -d)"
pids=()

cleanup() {
    for pid in "${pids[@]:-}"; do
        kill "$pid" 2>/dev/null || true
    done
    if [ "${SUFI_TEST_KEEP:-}" = "1" ]; then
        echo "sandbox kept at $sandbox"
    else
        rm -rf "$sandbox"
    fi
}
trap cleanup EXIT

say() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

say 'php lint'
find "$root" -name '*.php' -print0 | xargs -0 -n1 "$php" -l > /dev/null
echo '  ok'

# make_instance <dir> <base_path> <port> <allow_private_fetch> [password]
make_instance() {
    local dir="$1" base="$2" inst_port="$3" allow_private="$4" pass="${5:-testpass}"

    mkdir -p "$dir"
    tar -C "$root" \
        --exclude=.git --exclude='sys/*.sqlite' --exclude='sys/*.pem' --exclude='sys/logs' \
        -cf - . | tar -C "$dir" -xf -

    openssl genrsa -out "$dir/sys/private.pem" 2048 2>/dev/null
    openssl rsa -in "$dir/sys/private.pem" -pubout -out "$dir/sys/public.pem" 2>/dev/null

    cat >> "$dir/sys/config.php" <<PHP

// ── test overrides ───────────────────────────────────────────────────────────
\$server        = '127.0.0.1:$inst_port';
\$username      = 'alice';
\$password      = '$pass';
\$base_path     = '$base';
\$cookie_secure = false;
\$base_url      = 'http://127.0.0.1:$inst_port$base/';
\$actor_name    = 'Alice';
\$remote_scheme = 'http';
\$allow_private_fetch = $allow_private;
\$outbox_page_size = 2;
PHP
}

say 'building sandbox'
make_instance "$sandbox/root" '' "$port" true
# The prefixed instance uses a bcrypt hash to exercise password_verify().
hash=$("$php" -r 'echo password_hash("testpass", PASSWORD_DEFAULT);')
make_instance "$sandbox/prefix" '/sufi' "$port_prefix" false "$hash"
openssl genrsa -out "$sandbox/remote.pem" 2048 2>/dev/null
export SUFI_REMOTE_KEY="$sandbox/remote.pem"
export SUFI_REMOTE_LOG="$sandbox/remote-inbox.log"
: > "$SUFI_REMOTE_LOG"
echo '  ok'

say 'starting servers'
"$php" -S "127.0.0.1:$port"        "$sandbox/root/index.php"  > "$sandbox/root.log"   2>&1 & pids+=($!)
"$php" -S "127.0.0.1:$port_prefix" "$sandbox/prefix/index.php" > "$sandbox/prefix.log" 2>&1 & pids+=($!)
"$php" -S "127.0.0.1:$port_remote" "$root/tests/remote.php"    > "$sandbox/remote.log" 2>&1 & pids+=($!)

for _ in $(seq 1 50); do
    "$php" -r 'exit(@file_get_contents($argv[1]) ? 0 : 1);' "http://127.0.0.1:$port/ap/actor" && break
    sleep 0.1
done
echo '  ok'

export SUFI_URL="http://127.0.0.1:$port"
export SUFI_PREFIX_URL="http://127.0.0.1:$port_prefix"
export SUFI_REMOTE_URL="http://127.0.0.1:$port_remote"
export SUFI_DB="$sandbox/root/sys/sufi.sqlite"
export SUFI_PREFIX_DB="$sandbox/prefix/sys/sufi.sqlite"
export SUFI_PREFIX_KEY="$sandbox/prefix/sys/private.pem"

say 'running tests'
if ! "$php" "$root/tests/run.php"; then
    echo
    echo '--- instance log ---'
    tail -n 20 "$sandbox/root.log" || true
    echo '--- prefix log ---'
    tail -n 20 "$sandbox/prefix.log" || true
    exit 1
fi
