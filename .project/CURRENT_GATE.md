# BoatOps Current Gate

Updated: 2026-08-12 12:00 Asia/Bangkok

## Current decision

```text
REAL_PILOT_EXECUTION
CORE_SAFETY_COMPLETE
TEST_RUNTIME_READY
SYNTHETIC_VERTICAL_SLICE_COMPLETE
REAL_PILOT_AUTHORIZED
PLAN_C_CONFIGURATION_READY
NO_NEW_FEATURE_DEVELOPMENT
```

BoatOps has left the active Deployment Readiness / governance-planning phase. The current job is the shortest TEST-only path to real Plan C use. No new feature package is open.

The exact machine-readable state is in `CURRENT_STATE.yaml`; the small operational queue is in `REVIEW_QUEUE.md`.

`merge != deploy != cutover != release`

## Exact GitHub identity

```text
canonical main:
  36fe230a12e3d24a7bcb8c0333f3ec15012c029e
  Real Pilot candidate included: NO

Real Pilot branch:
  codex/test-runtime-vertical-slice

bounded implementation candidate:
  987eba04a1dc9073be6c02631792808debc35635
  relative to main: ahead 2 / behind 0

PR #18:
  OPEN / DRAFT / CLEAN / NOT_MERGED
```

The state-sync commit is a documentation-only descendant on PR #18. The immutable Real Pilot implementation candidate remains `987eba04a1dc9073be6c02631792808debc35635`; the live PR branch head must be resolved from GitHub at review time.

## TEST runtime

`http://43.156.151.62:8080` is the selected TEST-only runtime.

Verified state:

- Ubuntu 24.04, PHP 8.4, Laravel 13;
- PostgreSQL 16.14 with `btree_gist` 1.7;
- migrations: 19 ran / 0 pending;
- `/up`: HTTP 200;
- Nginx, PHP-FPM, PostgreSQL, and Scheduler: active;
- PostgreSQL backup, restore proof, rollback proof, and `holds:expire` scheduler proof: PASS;
- existing Docker services and public `:80`: untouched.

This makes the TEST runtime `READY`. It does not mean the exact Real Pilot candidate is already deployed.

## Completed synthetic proof

DR04 implementation and synthetic proof are complete.

```text
synthetic provision = CREATED
exact rerun = UNCHANGED
configuration drift = FAIL CLOSED / ZERO WRITE
late failure rollback = PASS
```

The TEST Synthetic Vertical Slice passed:

```text
Login
-> Calendar
-> Inquiry
-> HOLD
-> Confirm
-> Booking Workbench
-> Prepare
-> Depart
-> Return
-> Complete
-> BLOCK
-> Audit
```

Early Complete failed closed and cross-organization Audit leakage was zero.

## Real Pilot authorization and configuration

Owner authorization is recorded as:

```text
REAL_PILOT = AUTHORIZED
TEST_ONLY = true
REAL_OPERATOR_USE = AUTHORIZED
REAL_PILOT_CONFIGURATION = AUTHORIZED
```

Plan C configuration is `READY`:

- Organization: `Ayany Boat Operations`, `Asia/Bangkok`;
- Boat: `Plan C`, buffer `30 / 30` minutes;
- HOLD TTL: `30` minutes;
- Operator: `Cao <cao@mukuy.com>` with Calendar, Booking Workflow, and BLOCK permissions;
- Slots: `09:00-13:00` 4h AM, `14:00-18:00` 4h PM, `12:00-18:00` 6h, and `10:00-18:00` 8h;
- every Slot applies to Plan C and has `operating_time_status=VERIFIED`;
- compatibility is empty, so overlapping use of the same Boat remains fail-closed.

The observed-pain fix at `987eba04a1dc9073be6c02631792808debc35635` only added `VERIFIED` manifest support and its focused test. Pint, 7 PilotProvisioning tests / 39 assertions, the full 283-test / 3293-assertion PHP suite, and GitHub CI passed. Do not reopen this issue.

## Current blocker

```text
PLAN_C_REAL_CONFIG = READY
TEST_DEPLOYED_REAL_PILOT_HEAD = NOT_YET
PLAN_C_PROVISIONING = PENDING
REAL_OPERATOR_SECRET = NOT_CONFIGURED
CAO_LOGIN_SMOKE = PENDING
```

This is an execution-secret blocker, not a code blocker. Do not add code to bypass secret injection.

## Allowed now

Only the following Real Pilot execution path is active:

```text
deploy exact Real Pilot candidate to TEST
-> provision Plan C
-> Cao login/calendar smoke
-> next real Plan C order
-> record observed operational pain
```

The next real order will use:

```text
Inquiry -> HOLD -> Confirm -> Prepare -> Depart -> Return -> Complete -> Audit
```

Historical Plan C orders will not be migrated. Do not create a synthetic or invented “real” order.

## Development rule

```text
NO_NEW_FEATURE_DEVELOPMENT
UNLESS:
  - REAL_PILOT_BLOCKER
  - OBSERVED_OPERATIONAL_PAIN
  - UNIVERSAL_CORE_SAFETY_DEFECT
```

Admin UI, setup wizard, capacity/seat inventory, Product engine, CRM, Finance expansion, reporting, maintenance, historical migration, ChannelHub, OTA, second-company onboarding, and SaaS administration remain deferred.

Routine progress does not require a governance-only PR. State/document updates normally travel with the relevant implementation PR.

## Parallel item

DR16 remains `PARALLEL_BEFORE_CUTOVER / NOT_CURRENT_REAL_PILOT_BLOCKER`.

```text
main.protected = false
repository rulesets = 0
DR16 mutation authorized = false
```

This task does not change GitHub settings.

## Explicit boundaries

```text
PRODUCTION_DEPLOYMENT = false
CUTOVER = false
AUTHORITY_SWITCH = false
TAG = false
RELEASE = false
```

PR #18 remains Draft and is not merged by this state synchronization. Production, Docker, and public `:80` remain untouched.

## Closed history

Core Safety and the prior Deployment Readiness planning work remain accepted history, not active queue items. PR #12, PR #15, and PR #16 are merged/closed; their evidence remains in Git history and the branch ledger.
