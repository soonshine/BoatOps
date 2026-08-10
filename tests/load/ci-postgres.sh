#!/usr/bin/env bash
set -Eeuo pipefail

HTTP_LOG="${RUNNER_TEMP:-/tmp}/boatops-postgres-http.log"
SERVER_PID=""

finish() {
    local rc=$?

    if [[ -n "$SERVER_PID" ]]; then
        kill -- "-$SERVER_PID" 2>/dev/null || true
        wait "$SERVER_PID" 2>/dev/null || true
    fi

    if (( rc != 0 )); then
        printf '\n===== PHP multi-worker HTTP server log =====\n'
        if [[ -f "$HTTP_LOG" ]]; then
            cat "$HTTP_LOG"
        fi
        printf '\n===== Laravel logs =====\n'
        shopt -s nullglob
        for log in storage/logs/*.log; do
            printf '\n--- %s ---\n' "$log"
            cat "$log"
        done
    fi

    trap - EXIT
    exit "$rc"
}
trap finish EXIT

export PGPASSWORD="$DB_PASSWORD"
PSQL=(
    psql
    --host="$DB_HOST"
    --port="$DB_PORT"
    --username="$DB_USERNAME"
    --dbname="$DB_DATABASE"
    --no-psqlrc
    --set=ON_ERROR_STOP=1
)

php artisan migrate:fresh --force --no-interaction
BOATOPS_DEMO_TOKEN="$BOATOPS_TOKEN" php artisan db:seed --force --no-interaction

CONSTRAINT_COUNT="$("${PSQL[@]}" --tuples-only --no-align --command="
    SELECT count(*)
    FROM pg_constraint c
    JOIN pg_class t ON t.oid = c.conrelid
    WHERE t.relname = 'allocations'
      AND c.conname = 'allocations_no_active_overlap'
      AND c.contype = 'x'
      AND c.convalidated
      AND c.condeferrable;
")"
test "$CONSTRAINT_COUNT" = "1"

echo 'Verified allocations_no_active_overlap exists and is validated/deferrable.'

BOATOPS_BOAT_ID="$("${PSQL[@]}" --tuples-only --no-align --command="
    SELECT b.id
    FROM boats b
    JOIN api_clients c ON c.organization_id = b.organization_id
    WHERE c.name = 'Local Demo API Client'
    ORDER BY b.id
    LIMIT 1;
")"
BOATOPS_TRIP_TEMPLATE_ID="$("${PSQL[@]}" --tuples-only --no-align --command="
    SELECT t.id
    FROM trip_templates t
    JOIN api_clients c ON c.organization_id = t.organization_id
    WHERE c.name = 'Local Demo API Client'
      AND t.code = 'DEMO-4H'
    ORDER BY t.id
    LIMIT 1;
")"
test -n "$BOATOPS_BOAT_ID"
test -n "$BOATOPS_TRIP_TEMPLATE_ID"
export BOATOPS_BOAT_ID BOATOPS_TRIP_TEMPLATE_ID

"${PSQL[@]}" --command="
DO \$\$
DECLARE
    fixture_organization_id bigint;
    first_allocation_id bigint;
BEGIN
    SELECT organization_id INTO fixture_organization_id
    FROM boats
    WHERE id = ${BOATOPS_BOAT_ID};

    INSERT INTO allocations (
        organization_id, boat_id, allocation_type, status,
        business_start, business_end, occupied_start, occupied_end,
        created_at, updated_at
    ) VALUES (
        fixture_organization_id, ${BOATOPS_BOAT_ID}, 'HOLD', 'ACTIVE',
        '2099-01-01 10:00:00+00', '2099-01-01 12:00:00+00',
        '2099-01-01 10:00:00+00', '2099-01-01 12:00:00+00',
        now(), now()
    ) RETURNING id INTO first_allocation_id;

    BEGIN
        INSERT INTO allocations (
            organization_id, boat_id, allocation_type, status,
            business_start, business_end, occupied_start, occupied_end,
            created_at, updated_at
        ) VALUES (
            fixture_organization_id, ${BOATOPS_BOAT_ID}, 'BLOCKED', 'ACTIVE',
            '2099-01-01 11:00:00+00', '2099-01-01 13:00:00+00',
            '2099-01-01 11:00:00+00', '2099-01-01 13:00:00+00',
            now(), now()
        );
        RAISE EXCEPTION 'allocations_no_active_overlap accepted an overlap';
    EXCEPTION WHEN exclusion_violation THEN
        RAISE NOTICE 'allocations_no_active_overlap rejected the fictional overlap';
    END;

    DELETE FROM allocations WHERE id = first_allocation_id;
END
\$\$;
"

echo 'Verified allocations_no_active_overlap rejects an ACTIVE overlap.'

setsid env PHP_CLI_SERVER_WORKERS="$PHP_CLI_SERVER_WORKERS" \
    php -S 127.0.0.1:18080 -t public public/index.php \
    >"$HTTP_LOG" 2>&1 &
SERVER_PID=$!

for _ in $(seq 1 80); do
    if curl --fail --silent --show-error "$BOATOPS_BASE_URL/" >/dev/null; then
        break
    fi
    sleep 0.25
done
curl --fail --silent --show-error "$BOATOPS_BASE_URL/" >/dev/null

WORKER_COUNT="$(ps --no-headers --ppid "$SERVER_PID" 2>/dev/null | wc -l | tr -d '[:space:]')"
test "$WORKER_COUNT" -ge 4
echo "Verified real PHP HTTP worker processes: $WORKER_COUNT"

export BOATOPS_PHP=php
export BOATOPS_APP_DIR="$GITHUB_WORKSPACE"
node tests/load/hold-concurrency.mjs
node tests/load/hold-expiry-race.mjs
node tests/load/operation-races.mjs

CORE_SAFETY_RESULT="$(node tests/load/core-safety-inventory.mjs)"
echo "$CORE_SAFETY_RESULT"
CORE_SAFETY_TRIP_ID="$(node -e 'console.log(JSON.parse(process.argv[1]).trip_id)' "$CORE_SAFETY_RESULT")"
CORE_SAFETY_BOOKING_ID="$(node -e 'console.log(JSON.parse(process.argv[1]).booking_id)' "$CORE_SAFETY_RESULT")"
CORE_SAFETY_ORGANIZATION_ID="$(node -e 'console.log(JSON.parse(process.argv[1]).organization_id)' "$CORE_SAFETY_RESULT")"
CORE_SAFETY_EXPECTED_REVISION="$(node -e 'console.log(JSON.parse(process.argv[1]).expected_revision)' "$CORE_SAFETY_RESULT")"
CORE_SAFETY_COMPLETE_KEY="$(node -e 'console.log(JSON.parse(process.argv[1]).complete_key)' "$CORE_SAFETY_RESULT")"

CORE_SAFETY_DB_STATE="$("${PSQL[@]}" --tuples-only --no-align --field-separator='|' --command="
    SELECT concat_ws('|',
        trips.status,
        bookings.status,
        allocations.status,
        organizations.inventory_revision,
        (SELECT count(*) FROM outbox_events
            WHERE organization_id = ${CORE_SAFETY_ORGANIZATION_ID}
              AND event_type = 'trip.completed.v1'),
        (SELECT count(*) FROM audit_logs
            WHERE organization_id = ${CORE_SAFETY_ORGANIZATION_ID}
              AND action = 'trip.completed'),
        (SELECT count(*) FROM idempotency_keys
            WHERE organization_id = ${CORE_SAFETY_ORGANIZATION_ID}
              AND operation = 'completeTrip:${CORE_SAFETY_TRIP_ID}'
              AND idempotency_key = '${CORE_SAFETY_COMPLETE_KEY}')
    )
    FROM trips
    JOIN bookings ON bookings.id = trips.booking_id
    JOIN allocations ON allocations.id = bookings.allocation_id
    JOIN organizations ON organizations.id = trips.organization_id
    WHERE trips.id = ${CORE_SAFETY_TRIP_ID}
      AND bookings.id = ${CORE_SAFETY_BOOKING_ID};
")"
test "$CORE_SAFETY_DB_STATE" = "RETURNED|CONFIRMED|ACTIVE|${CORE_SAFETY_EXPECTED_REVISION}|0|0|0"
echo 'Verified PostgreSQL early Complete is side-effect free and preserves ACTIVE inventory authority against competing HOLD/BLOCK.'
