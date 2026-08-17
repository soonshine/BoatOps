#!/usr/bin/env bash
set -Eeuo pipefail

usage() {
    cat <<'EOF'
Usage:
  sudo bash deploy/scripts/deploy-production.sh <git-sha> --backup-confirmed

Required production layout:
  /www/wwwroot/boatops.ayany.com/shared/.env
  /www/wwwroot/boatops.ayany.com/shared/storage/
  /www/wwwroot/boatops.ayany.com/releases/
  /www/wwwroot/boatops.ayany.com/current -> releases/<release>

The caller must create and verify a PostgreSQL backup before passing
--backup-confirmed. Database migrations are not automatically rolled back;
production migrations must remain backward-compatible with the previous
application release.
EOF
}

fail() {
    echo "[BoatOps deploy] ERROR: $*" >&2
    exit 1
}

SHA="${1:-}"
BACKUP_CONFIRMATION="${2:-}"

[[ -n "$SHA" ]] || { usage; exit 2; }
[[ "$BACKUP_CONFIRMATION" == "--backup-confirmed" ]] || fail "database backup confirmation is required"
[[ "$SHA" =~ ^[0-9a-fA-F]{40}$ ]] || fail "git SHA must be a full 40-character commit SHA"
[[ "${EUID}" -eq 0 ]] || fail "run as root so ownership and service restart are deterministic"

ROOT="${BOATOPS_ROOT:-/www/wwwroot/boatops.ayany.com}"
REPO="${BOATOPS_REPO:-https://github.com/soonshine/BoatOps.git}"
PHP_BIN="${BOATOPS_PHP:-/www/server/php/84/bin/php}"
COMPOSER_BIN="${BOATOPS_COMPOSER:-composer}"
NPM_BIN="${BOATOPS_NPM:-npm}"
WEB_USER="${BOATOPS_WEB_USER:-www}"
WEB_GROUP="${BOATOPS_WEB_GROUP:-www}"
QUEUE_SERVICE="${BOATOPS_QUEUE_SERVICE:-boatops-queue.service}"
SMOKE_BASE="${BOATOPS_SMOKE_BASE:-http://127.0.0.1:18081}"
HOST_HEADER="${BOATOPS_HOST_HEADER:-boatops.ayany.com}"
SHARED_ENV="$ROOT/shared/.env"
SHARED_STORAGE="$ROOT/shared/storage"
RELEASES="$ROOT/releases"
CURRENT="$ROOT/current"

for command_name in git curl "$COMPOSER_BIN" "$NPM_BIN" systemctl; do
    command -v "$command_name" >/dev/null 2>&1 || fail "missing command: $command_name"
done
[[ -x "$PHP_BIN" ]] || fail "PHP binary not executable: $PHP_BIN"
[[ -f "$SHARED_ENV" ]] || fail "missing production env: $SHARED_ENV"

env_value() {
    local key="$1"
    sed -n "s/^${key}=//p" "$SHARED_ENV" | tail -n 1 | sed -e 's/^\"//' -e 's/\"$//' -e "s/^'//" -e "s/'$//"
}

[[ "$(env_value APP_ENV)" == "production" ]] || fail "APP_ENV must be production"
[[ "$(env_value APP_DEBUG)" == "false" ]] || fail "APP_DEBUG must be false"
[[ "$(env_value APP_URL)" == "https://boatops.ayany.com" || "$(env_value APP_URL)" == "https://boatops.ayany.com/" ]] || fail "APP_URL must be https://boatops.ayany.com"
[[ "$(env_value DB_CONNECTION)" == "pgsql" ]] || fail "DB_CONNECTION must be pgsql"
[[ "$(env_value BOATOPS_DEMO_SITE_ENABLED)" == "false" ]] || fail "public Demo must be disabled on the real-use runtime"

mkdir -p "$RELEASES" "$ROOT/shared" "$SHARED_STORAGE"

RELEASE_ID="$(date -u +%Y%m%dT%H%M%SZ)-${SHA:0:12}"
RELEASE="$RELEASES/$RELEASE_ID"
[[ ! -e "$RELEASE" ]] || fail "release already exists: $RELEASE"

echo "[BoatOps deploy] preparing $RELEASE_ID"
git clone --quiet --no-checkout "$REPO" "$RELEASE"
cd "$RELEASE"
git fetch --quiet --depth 1 origin "$SHA"
git checkout --quiet --detach "$SHA"
ACTUAL_SHA="$(git rev-parse HEAD)"
[[ "$ACTUAL_SHA" == "$SHA" ]] || fail "checked out $ACTUAL_SHA instead of requested $SHA"

ln -s "$SHARED_ENV" .env

if [[ ! -d "$SHARED_STORAGE/framework" ]]; then
    cp -a storage/. "$SHARED_STORAGE/"
fi
rm -rf storage
ln -s "$SHARED_STORAGE" storage

mkdir -p "$SHARED_STORAGE/framework/cache" "$SHARED_STORAGE/framework/sessions" "$SHARED_STORAGE/framework/views" "$SHARED_STORAGE/logs" bootstrap/cache
chown -R "$WEB_USER:$WEB_GROUP" "$SHARED_STORAGE" bootstrap/cache

"$COMPOSER_BIN" install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress
"$NPM_BIN" ci --ignore-scripts
"$NPM_BIN" run build

"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan migrate:status >/dev/null

chown -R "$WEB_USER:$WEB_GROUP" "$SHARED_STORAGE" bootstrap/cache

PREVIOUS=""
if [[ -L "$CURRENT" ]]; then
    PREVIOUS="$(readlink -f "$CURRENT")"
fi

NEXT_LINK="$ROOT/.current-next"
rm -f "$NEXT_LINK"
ln -s "$RELEASE" "$NEXT_LINK"
mv -Tf "$NEXT_LINK" "$CURRENT"

rollback_code() {
    if [[ -n "$PREVIOUS" && -d "$PREVIOUS" ]]; then
        echo "[BoatOps deploy] smoke failed; restoring previous code: $PREVIOUS" >&2
        rm -f "$NEXT_LINK"
        ln -s "$PREVIOUS" "$NEXT_LINK"
        mv -Tf "$NEXT_LINK" "$CURRENT"
        systemctl restart "$QUEUE_SERVICE" || true
    else
        echo "[BoatOps deploy] smoke failed and no previous current symlink was available" >&2
    fi
}

systemctl restart "$QUEUE_SERVICE"
systemctl is-active --quiet "$QUEUE_SERVICE" || { rollback_code; fail "queue service is not active"; }

if ! curl --fail --silent --show-error --max-time 10 -H "Host: $HOST_HEADER" "$SMOKE_BASE/up" >/dev/null; then
    rollback_code
    fail "/up smoke check failed"
fi

ROOT_HEADERS="$(curl --silent --show-error --max-time 10 -I -H "Host: $HOST_HEADER" "$SMOKE_BASE/")" || {
    rollback_code
    fail "root smoke request failed"
}
echo "$ROOT_HEADERS" | grep -Eqi '^HTTP/[^ ]+ 30[12378]' || { rollback_code; fail "root did not redirect"; }
echo "$ROOT_HEADERS" | grep -Eqi '^Location: /operator/today\r?$' || { rollback_code; fail "root did not redirect to /operator/today"; }

TODAY_HEADERS="$(curl --silent --show-error --max-time 10 -I -H "Host: $HOST_HEADER" "$SMOKE_BASE/operator/today")" || {
    rollback_code
    fail "operator today smoke request failed"
}
echo "$TODAY_HEADERS" | grep -Eqi '^HTTP/[^ ]+ 30[12378]' || { rollback_code; fail "unauthenticated /operator/today did not redirect"; }
echo "$TODAY_HEADERS" | grep -Eqi '^Location: .*/operator/login\r?$' || { rollback_code; fail "unauthenticated /operator/today did not redirect to login"; }

CURRENT_SHA="$(git -C "$CURRENT" rev-parse HEAD)"
[[ "$CURRENT_SHA" == "$SHA" ]] || { rollback_code; fail "current symlink does not resolve to requested SHA"; }

echo "[BoatOps deploy] PASS"
echo "deployed_sha=$CURRENT_SHA"
echo "release=$RELEASE_ID"
echo "previous=${PREVIOUS:-NONE}"
echo "smoke_base=$SMOKE_BASE"
