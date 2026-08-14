# BoatOps Current Gate

Updated: 2026-08-14 12:30 Asia/Bangkok

## Current decision

```text
REAL_PILOT_EXECUTION
CORE_SAFETY_COMPLETE
TEST_RUNTIME_READY
SYNTHETIC_VERTICAL_SLICE_COMPLETE
REAL_PILOT_AUTHORIZED
PLAN_C_CONFIGURATION_READY
TEST_REAL_PILOT_CANDIDATE_DEPLOYED
PRELAUNCH_PASSWORDLESS_ENABLED_TEST
CAO_PASSWORDLESS_LOGIN_SMOKE_PASS
NO_NEW_FEATURE_DEVELOPMENT
```

BoatOps has left the active Deployment Readiness / governance-planning phase. The current job is the shortest TEST-only path to real Plan C use. No new feature package is open.

The exact machine-readable state is in `CURRENT_STATE.yaml`; the small operational queue is in `REVIEW_QUEUE.md`.

`merge != deploy != cutover != release`

## Exact GitHub identity

```text
canonical main:
  live GitHub head observed: 2f59fb67ab8eea830ef6f8860ed0ee8a2acd9aa7
  original Real Pilot candidate included: YES
  passwordless candidate included: NO (TEST-only post-merge candidate)
  authoring base for this branch: 36fe230a12e3d24a7bcb8c0333f3ec15012c029e

Real Pilot branch:
  codex/test-runtime-vertical-slice

bounded implementation candidate:
  987eba04a1dc9073be6c02631792808debc35635
  relative to main: ahead 2 / behind 0

passwordless TEST candidate:
  c9c5493468757643269178f7fac3353b14b90ad5
  exact source deployed to TEST

PR #18:
  MERGED / CLOSED
  historical merge commit: 00a029c9a3dcd2122a958514e845334d0a295ac9
  post-merge TEST candidate: c9c5493468757643269178f7fac3353b14b90ad5
```

PR #18 is already merged; the immutable prior Real Pilot implementation candidate remains `987eba04a1dc9073be6c02631792808debc35635`, and the passwordless TEST candidate is its post-merge descendant `c9c5493468757643269178f7fac3353b14b90ad5`.

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
- exact passwordless TEST candidate: `c9c5493468757643269178f7fac3353b14b90ad5`;
- `PRELAUNCH_PASSWORDLESS=true` and the configured Cao selector are active on TEST;
- `/operator/login` redirects without a password and `/operator/calendar` returns HTTP 200;
- organization and four Plan C Slots are visible; unauthenticated `/operator/calendar` remains guarded.

This makes the TEST runtime `READY` with the exact passwordless candidate deployed. It remains TEST-only and is not a Production or Release claim.

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

## Verified passwordless TEST execution

```text
PLAN_C_REAL_CONFIG = READY
TEST_DEPLOYED_SHA = c9c5493468757643269178f7fac3353b14b90ad5
PRELAUNCH_PASSWORDLESS = ENABLED (TEST)
APPLICATION_PASSWORD_REQUIRED = NO
EFFECTIVE_OPERATOR = Cao
ORGANIZATION = Ayany Boat Operations
REAL_PILOT_TIMEZONE_VERIFY = PASS_TIMEZONE_NORMALIZATION_CORRECT
CAO_LOGIN_SMOKE = PASS_PASSWORDLESS
BUSINESS_DATA_CHANGED = NO
EXISTING_REAL_ORDERS_CHANGED = NO
INFRASTRUCTURE_AUTH_CHANGED = NO
```

The GET entry point creates a normal Laravel Auth session and then uses the existing membership middleware and permission priority. No Operator route was opened and no password or infrastructure-auth change was made.

## Current operational path

The passwordless login/calendar smoke is complete. Only the following Real Pilot execution path remains active:

```text
next genuine Plan C order
-> Inquiry
-> HOLD
-> Confirm
-> Prepare
-> Depart
-> Return
-> Complete
-> Audit
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
PRELAUNCH_PASSWORDLESS_TEST_ONLY = true
APPLICATION_PASSWORD_REQUIRED = false
INFRASTRUCTURE_AUTH_CHANGED = false
BUSINESS_DATA_CHANGED = false
EXISTING_REAL_ORDERS_CHANGED = false
TAG = false
RELEASE = false
```

PR #18 is historical `MERGED / CLOSED`; this post-merge candidate remains on `codex/test-runtime-vertical-slice` for TEST verification only. Production, Docker, and public `:80` remain untouched.

## Closed history

Core Safety and the prior Deployment Readiness planning work remain accepted history, not active queue items. PR #12, PR #15, PR #16, and PR #18 are merged/closed; the passwordless TEST candidate is `c9c5493468757643269178f7fac3353b14b90ad5` and remains TEST-only. Their evidence remains in Git history and the branch ledger.

## Status synchronization

The following Owner-provided status is recorded without backfilling execution history:

```text
PLANC-20260812-TES-CAO = CONFIRMED / PLANNED / EXECUTION=UNKNOWN
PLANC-20260823-TEST = CONFIRMED_WAITING_PREPARATION
REAL-PILOT-TIMEZONE-VERIFY = PASS_TIMEZONE_NORMALIZATION_CORRECT
PRELAUNCH_PASSWORDLESS = ENABLED (TEST)
DECISION = ACCEPT_EXECUTION_UNKNOWN_NO_HISTORICAL_FILL
```

`EXECUTION=UNKNOWN` is accepted as stated. No historical Plan C order, execution receipt, or synthetic “real” order was created to fill the gap.
