#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$ROOT_DIR"

fail() {
    printf 'BoatOps check preflight failed: %s\n' "$1" >&2
    printf 'See ENVIRONMENT.md for the project-owned setup contract.\n' >&2
    exit 2
}

for command_name in php composer node npm; do
    command -v "$command_name" >/dev/null 2>&1 \
        || fail "required command '$command_name' is not available"
done

[[ -f vendor/autoload.php ]] \
    || fail "PHP dependencies are missing; run the documented composer install command"
[[ -d node_modules ]] \
    || fail "Node.js dependencies are missing; run the documented npm ci command"

run_check() {
    local label="$1"
    shift

    printf '\n===== %s =====\n' "$label"
    "$@"
}

run_check 'PHP tests' composer test
run_check 'PHP formatting' vendor/bin/pint --test
run_check 'API contracts and event fixtures' npm run test:contract
run_check 'Frontend build' npm run build

printf '\nBoatOps portable check: PASS (4/4)\n'
