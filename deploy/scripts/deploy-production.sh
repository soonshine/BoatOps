#!/usr/bin/env bash
set -Eeuo pipefail

usage() {
    cat <<'EOF'
Usage:
  sudo bash deploy/scripts/deploy-production.sh <git-sha> --backup-confirmed [--rehearsal]

Required production layout:
  /www/wwwroot/boatops.ayany.com/shared/.env
  /www/wwwroot/boatops.ayany.com/shared/storage/
  /www/wwwroot/boatops.ayany.com/releases/
  /www/wwwroot/boatops.ayany.com/current -> releases/<release>

Security boundary (Issue #49):
  - privileged filesystem / ownership / atomic-symlink / mutex work stays under root;
  - repository-controlled composer / npm / artisan commands run as a non-root
    deploy/web user (BOATOPS_WEB_USER, default www) via runuser or su;
  - a single-instance flock mutex blocks concurrent deployments.

The caller must create and verify a PostgreSQL backup before passing
--backup-confirmed. Database migrations are not automatically rolled back;
production migrations must remain backward-compatible with the previous
application release.

--rehearsal performs a bounded production-host dry run: exact-SHA checkout,
non-root composer/npm/artisan capability and mutex exercise, without switching
current and without running production migrations. It still requires
--backup-confirmed so the complete deployment acknowledgement flow is proven.
EOF
}

fail() {
    echo "[BoatOps deploy] ERROR: $*" >&2
    exit 1
}

blade_requires_frontend_build() {
    local blade_file="$1"
    local line
    local optional_vite_depth=0
    local asset_reference_pattern='@vite([^[:alnum:]_]|$)|@viteReactRefresh|Vite::asset[[:space:]]*\(|(^|[^[:alnum:]_-])mix[[:space:]]*\('

    while IFS= read -r line || [[ -n "$line" ]]; do
        if (( optional_vite_depth > 0 )); then
            if [[ "$line" == *"@if"* ]]; then
                optional_vite_depth=$((optional_vite_depth + 1))
            fi
            if [[ "$line" == *"@endif"* ]]; then
                optional_vite_depth=$((optional_vite_depth - 1))
            fi
            continue
        fi

        if [[ "$line" == *"@if"* \
            && "$line" == *"file_exists(public_path('build/manifest.json'))"* \
            && "$line" == *"file_exists(public_path('hot'))"* ]]; then
            optional_vite_depth=1
            continue
        fi

        if [[ "$line" =~ $asset_reference_pattern ]]; then
            return 0
        fi
    done < "$blade_file"

    return 1
}

release_requires_frontend_build() {
    local release_root="$1"
    local blade_file

    if [[ -f "$release_root/public/build/manifest.json" || -f "$release_root/public/mix-manifest.json" ]]; then
        return 0
    fi

    [[ -d "$release_root/resources/views" ]] || return 1

    while IFS= read -r -d '' blade_file; do
        if blade_requires_frontend_build "$blade_file"; then
            return 0
        fi
    done < <(find "$release_root/resources/views" -type f -name '*.blade.php' -print0)

    return 1
}
# ---------------------------------------------------------------------------
# Issue #49 non-root execution boundary
# ---------------------------------------------------------------------------
# run_repository_command executes the given bash code with positional $1 set
# to the release root. When a non-root deploy user is configured, the command
# runs as that user through the minimal privilege-drop primitives available on
# the host (runuser, with su as fallback); otherwise it runs directly (used by
# the contract test fixtures). Root only prepares ownership and never executes
# repository-controlled composer / npm / artisan commands.
run_repository_command() {
    local code="$1"

    if [[ -z "${WEB_USER:-}" || "$WEB_USER" == "root" ]]; then
        bash -c "$code" boatops-deploy-command "$RELEASE"
        return $?
    fi

    if [[ -n "${RUNUSER_BIN:-}" ]]; then
        "$RUNUSER_BIN" -u "$WEB_USER" -- \
            env -i \
            HOME="$WEB_USER_HOME" \
            PATH="$WEB_USER_PATH" \
            COMPOSER_BIN="$COMPOSER_BIN" \
            NPM_BIN="$NPM_BIN" \
            PHP_BIN="$PHP_BIN" \
            bash -c "$code" boatops-deploy-command "$RELEASE"
        return $?
    fi

    BOATOPS_WEB_CODE="$code" \
    BOATOPS_WEB_RELEASE="$RELEASE" \
    BOATOPS_WEB_HOME="$WEB_USER_HOME" \
    BOATOPS_WEB_PATH="$WEB_USER_PATH" \
    BOATOPS_WEB_COMPOSER="$COMPOSER_BIN" \
    BOATOPS_WEB_NPM="$NPM_BIN" \
    BOATOPS_WEB_PHP="$PHP_BIN" \
    "$SU_BIN" -s "$SU_SHELL" "$WEB_USER" -c \
      'env -i HOME="$BOATOPS_WEB_HOME" PATH="$BOATOPS_WEB_PATH" COMPOSER_BIN="$BOATOPS_WEB_COMPOSER" NPM_BIN="$BOATOPS_WEB_NPM" PHP_BIN="$BOATOPS_WEB_PHP" bash -c "$BOATOPS_WEB_CODE" boatops-deploy-command "$BOATOPS_WEB_RELEASE"'
}

# True when the deploy user can read a file; used to validate shared secrets.
web_user_can_read() {
    local file="$1"

    if [[ -n "${RUNUSER_BIN:-}" ]]; then
        "$RUNUSER_BIN" -u "$WEB_USER" -- test -r "$file"
        return $?
    fi

    "$SU_BIN" -s "$SU_SHELL" "$WEB_USER" -c "test -r '$file'"
}

# Root-level privileged ownership fix: keep root as owner, grant the deploy
# group read access so the non-root artisan flow can load shared secrets.
ensure_env_readable_by_web_user() {
    if web_user_can_read "$SHARED_ENV"; then
        return 0
    fi

    echo "[BoatOps deploy] shared .env must be readable by deploy user; fixing group access (root:$WEB_GROUP)" >&2
    chown root:"$WEB_GROUP" "$SHARED_ENV"
    chmod u+rw,g+r,o-rwx "$SHARED_ENV"
    web_user_can_read "$SHARED_ENV" || fail "shared env is still not readable by deploy user: $SHARED_ENV"
}

# Root-level privileged ownership work: give the non-root deploy user write
# access only to the paths repository-controlled commands need to write.
prepare_release_for_app_user() {
    local release_root="$1"

    mkdir -p "$release_root/vendor" "$release_root/bootstrap/cache"
    chown "$WEB_USER:$WEB_GROUP" "$release_root/vendor" "$release_root/bootstrap/cache"

    if release_requires_frontend_build "$release_root"; then
        mkdir -p "$release_root/node_modules" "$release_root/public/build"
        chown "$WEB_USER:$WEB_GROUP" "$release_root/node_modules" "$release_root/public/build"
    fi
}

build_frontend_if_required() {
    local release_root="$1"

    if ! release_requires_frontend_build "$release_root"; then
        echo "[BoatOps deploy] NOTICE: release does not require Vite/Mix assets; skipping npm"
        return 0
    fi

    command -v "$NPM_BIN" >/dev/null 2>&1 || fail "release requires Vite/Mix assets but npm is unavailable: $NPM_BIN"
    [[ -f "$release_root/package.json" && -f "$release_root/package-lock.json" ]] \
        || fail "release requires Vite/Mix assets but package.json/package-lock.json is missing"

    echo "[BoatOps deploy] release requires Vite/Mix assets; building locked frontend dependencies"
    if [[ -z "${WEB_USER:-}" || "$WEB_USER" == "root" ]]; then
        (
            cd "$release_root"
            "$NPM_BIN" ci --ignore-scripts
            "$NPM_BIN" run build
        )
    else
        run_repository_command '
set -Eeuo pipefail
cd "$1"
"$NPM_BIN" ci --ignore-scripts
"$NPM_BIN" run build
'
    fi

    [[ -f "$release_root/public/build/manifest.json" || -f "$release_root/public/mix-manifest.json" ]] \
        || fail "frontend build did not produce a Vite/Mix manifest"
}
SHA="${1:-}"
BACKUP_CONFIRMATION="${2:-}"
REHEARSAL_MODE=false
case "${3:-}" in
    "")
        ;;
    --rehearsal|--dry-run)
        REHEARSAL_MODE=true
        ;;
    *)
        usage
        exit 2
        ;;
esac

[[ -n "$SHA" ]] || { usage; exit 2; }
[[ "$BACKUP_CONFIRMATION" == "--backup-confirmed" ]] || fail "database backup confirmation is required"
[[ "$SHA" =~ ^[0-9a-fA-F]{40}$ ]] || fail "git SHA must be a full 40-character commit SHA"
[[ "${EUID}" -eq 0 ]] || fail "run as root so ownership and the release switch are deterministic"

ROOT="${BOATOPS_ROOT:-/www/wwwroot/boatops.ayany.com}"
REPO="${BOATOPS_REPO:-https://github.com/soonshine/BoatOps.git}"
PHP_BIN="${BOATOPS_PHP:-/www/server/php/84/bin/php}"
COMPOSER_BIN="${BOATOPS_COMPOSER:-composer}"
NPM_BIN="${BOATOPS_NPM:-npm}"
WEB_USER="${BOATOPS_WEB_USER:-www}"
WEB_GROUP="${BOATOPS_WEB_GROUP:-www}"
WEB_USER_PATH="${BOATOPS_WEB_PATH:-/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin}"
SU_SHELL="${BOATOPS_SU_SHELL:-/bin/bash}"
SCHEDULER_CRON_FILE="${BOATOPS_SCHEDULER_CRON_FILE:-/etc/cron.d/boatops-scheduler}"
SMOKE_BASE="${BOATOPS_SMOKE_BASE:-http://127.0.0.1:18081}"
HOST_HEADER="${BOATOPS_HOST_HEADER:-boatops.ayany.com}"
LOCK_FILE="${BOATOPS_LOCK_FILE:-$ROOT/.deploy.lock}"
SHARED_ENV="$ROOT/shared/.env"
SHARED_STORAGE="$ROOT/shared/storage"
RELEASES="$ROOT/releases"
CURRENT="$ROOT/current"

for command_name in git curl "$COMPOSER_BIN"; do
    command -v "$command_name" >/dev/null 2>&1 || fail "missing command: $command_name"
done
COMPOSER_BIN="$(command -v "$COMPOSER_BIN")"
[[ -x "$PHP_BIN" ]] || fail "PHP binary not executable: $PHP_BIN"
[[ -f "$SHARED_ENV" ]] || fail "missing production env: $SHARED_ENV"

# Non-root execution boundary preflight: the deploy user must exist, be a
# real non-root account, and a usable privilege-drop primitive must exist.
[[ -n "$WEB_USER" && "$WEB_USER" != "root" ]] || fail "WEB_USER must be a non-root deploy user (got: ${WEB_USER:-empty})"
WEB_UID="$(id -u "$WEB_USER" 2>/dev/null || true)"
[[ -n "$WEB_UID" && "$WEB_UID" -ne 0 ]] || fail "WEB_USER must exist and must not be uid 0: $WEB_USER"
RUNUSER_BIN="$(command -v runuser 2>/dev/null || true)"
SU_BIN="$(command -v su 2>/dev/null || true)"
[[ -n "$RUNUSER_BIN" || -n "$SU_BIN" ]] || fail "non-root execution boundary requires runuser or su"
WEB_USER_HOME="${BOATOPS_WEB_HOME:-$(getent passwd "$WEB_USER" 2>/dev/null | cut -d: -f6)}"
if [[ -z "$WEB_USER_HOME" || "$WEB_USER_HOME" == "/" ]]; then
    WEB_USER_HOME="$ROOT/shared/tooling"
    mkdir -p "$WEB_USER_HOME"
    chown "$WEB_USER:$WEB_GROUP" "$WEB_USER_HOME"
fi

env_value() {
    local key="$1"
    sed -n "s/^${key}=//p" "$SHARED_ENV" | tail -n 1 | sed -e 's/^\"//' -e 's/\"$//' -e "s/^'//" -e "s/'$//"
}

[[ "$(env_value APP_ENV)" == "production" ]] || fail "APP_ENV must be production"
[[ "$(env_value APP_DEBUG)" == "false" ]] || fail "APP_DEBUG must be false"
[[ "$(env_value APP_URL)" == "https://boatops.ayany.com" || "$(env_value APP_URL)" == "https://boatops.ayany.com/" ]] || fail "APP_URL must be https://boatops.ayany.com"
[[ "$(env_value DB_CONNECTION)" == "pgsql" ]] || fail "DB_CONNECTION must be pgsql"
[[ -n "$(env_value APP_KEY)" ]] || fail "APP_KEY must be present and preserved"

DEMO_ENABLED="$(env_value BOATOPS_DEMO_SITE_ENABLED)"
case "$DEMO_ENABLED" in
    "")
        echo "[BoatOps deploy] NOTICE: BOATOPS_DEMO_SITE_ENABLED is unset; application defaults keep Demo disabled"
        ;;
    false|FALSE|False)
        ;;
    *)
        fail "BOATOPS_DEMO_SITE_ENABLED must be false when configured"
        ;;
esac

[[ -r "$SCHEDULER_CRON_FILE" ]] || fail "missing BoatOps scheduler cron entry: $SCHEDULER_CRON_FILE"
grep -Eq '^[[:space:]]*\*[[:space:]]+\*[[:space:]]+\*[[:space:]]+\*[[:space:]]+\*[[:space:]]+' "$SCHEDULER_CRON_FILE" \
    || fail "BoatOps scheduler must run every minute: $SCHEDULER_CRON_FILE"
grep -Eq 'artisan[[:space:]]+schedule:run([[:space:]]|$)' "$SCHEDULER_CRON_FILE" \
    || fail "BoatOps scheduler cron entry must run artisan schedule:run: $SCHEDULER_CRON_FILE"

mkdir -p "$RELEASES" "$ROOT/shared" "$SHARED_STORAGE"

# Single-instance deployment mutex: flock on a stable lock file for the whole
# deployment lifetime so concurrent deployments cannot race.
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    fail "another deployment holds the single-instance mutex ($LOCK_FILE)"
fi
echo "[BoatOps deploy] single-instance mutex acquired: $LOCK_FILE"

RELEASE_ID="$(date -u +%Y%m%dT%H%M%SZ)-${SHA:0:12}"
if [[ "$REHEARSAL_MODE" == true ]]; then
    RELEASE_ID="${RELEASE_ID}-rehearsal"
fi
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

# Privileged ownership setup, then all repository-controlled commands below
# execute as the non-root deploy user through run_repository_command.
ensure_env_readable_by_web_user
prepare_release_for_app_user "$RELEASE"
# Composer probes git inside the release repo for version detection; the
# root-owned checkout must be marked safe for the non-root deploy user so the
# probe succeeds instead of degrading to a fetch-only fallback.
run_repository_command 'git config --global --add safe.directory "$1"'


run_repository_command '
set -Eeuo pipefail
cd "$1"
"$COMPOSER_BIN" install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress
' || fail "composer install failed"

build_frontend_if_required "$RELEASE"
ARTISAN_BLOCK='
set -Eeuo pipefail
cd "$1"
"$PHP_BIN" artisan optimize:clear
'
if [[ "$REHEARSAL_MODE" == true ]]; then
    ARTISAN_BLOCK="$ARTISAN_BLOCK"$'\n'
    ARTISAN_BLOCK="$ARTISAN_BLOCK"'echo "[BoatOps deploy] REHEARSAL: skipping production migrations"'
else
    ARTISAN_BLOCK="$ARTISAN_BLOCK"$'\n'
    ARTISAN_BLOCK="$ARTISAN_BLOCK"'"$PHP_BIN" artisan migrate --force'
fi
ARTISAN_BLOCK="$ARTISAN_BLOCK"$'\n'
ARTISAN_BLOCK="$ARTISAN_BLOCK"'
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan migrate:status >/dev/null
'
run_repository_command "$ARTISAN_BLOCK" || fail "artisan commands failed"

chown -R "$WEB_USER:$WEB_GROUP" "$SHARED_STORAGE" "$RELEASE/bootstrap/cache"

if [[ "$REHEARSAL_MODE" == true ]]; then
    echo "[BoatOps deploy] REHEARSAL: release prepared but current symlink was NOT switched"
    echo "[BoatOps deploy] REHEARSAL: no smoke checks / no rollback executed"
    echo "[BoatOps deploy] REHEARSAL release (immutable, not referenced by current): $RELEASE"
    echo "[BoatOps deploy] REHEARSAL PASS"
    exit 0
fi

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
    else
        echo "[BoatOps deploy] smoke failed and no previous current symlink was available" >&2
    fi
}

if ! curl --fail --silent --show-error --max-time 10 -H "Host: $HOST_HEADER" "$SMOKE_BASE/up" >/dev/null; then
    rollback_code
    fail "/up smoke check failed"
fi

ROOT_HEADERS="$(curl --silent --show-error --max-time 10 -I -H "Host: $HOST_HEADER" "$SMOKE_BASE/")" || {
    rollback_code
    fail "root smoke request failed"
}
echo "$ROOT_HEADERS" | tr -d '\r' | grep -Eqi '^HTTP/[^ ]+ 30[12378]' || { rollback_code; fail "root did not redirect"; }
echo "$ROOT_HEADERS" | tr -d '\r' | grep -Eqi '^Location: /operator/today$' || { rollback_code; fail "root did not redirect to /operator/today"; }

TODAY_HEADERS="$(curl --silent --show-error --max-time 10 -I -H "Host: $HOST_HEADER" "$SMOKE_BASE/operator/today")" || {
    rollback_code
    fail "operator today smoke request failed"
}
echo "$TODAY_HEADERS" | tr -d '\r' | grep -Eqi '^HTTP/[^ ]+ 30[12378]' || { rollback_code; fail "unauthenticated /operator/today did not redirect"; }
echo "$TODAY_HEADERS" | tr -d '\r' | grep -Eqi '^Location: .*/operator/login$' || { rollback_code; fail "unauthenticated /operator/today did not redirect to login"; }

CURRENT_SHA="$(git -C "$CURRENT" rev-parse HEAD)"
[[ "$CURRENT_SHA" == "$SHA" ]] || { rollback_code; fail "current symlink does not resolve to requested SHA"; }

echo "[BoatOps deploy] PASS"
echo "deployed_sha=$CURRENT_SHA"
echo "release=$RELEASE_ID"
echo "previous=${PREVIOUS:-NONE}"
echo "smoke_base=$SMOKE_BASE"
