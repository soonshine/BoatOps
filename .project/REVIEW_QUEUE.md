# BoatOps Operational Queue and Evidence Ledger

Last updated: 2026-08-14 12:30 Asia/Bangkok

The active queue is limited to the next genuine Real Pilot order. Passwordless TEST access, exact candidate deployment, and the requested status synchronization are completed evidence below, not active blockers. Completed Deployment Readiness, DR04, DR17 input, synthetic runtime, backup, restore, rollback, and scheduler items are closed evidence below, not active blockers.

## Active queue

| ID | Status | Next proof |
| --- | --- | --- |
| `REAL-PILOT-STATUS-SYNC` | `COMPLETE / EXECUTION_UNKNOWN_ACCEPTED` | Preserve the supplied UNKNOWN execution state; do not backfill history or invent an order |
| `FIRST-REAL-PLAN-C-VERTICAL-SLICE` | `PENDING / NEXT_REAL_ORDER` | Run the next genuine Plan C order from Inquiry through Audit; do not invent an order |
| `DR16` | `PARALLEL_BEFORE_CUTOVER / NOT_CURRENT_REAL_PILOT_BLOCKER` | Keep `main.protected=false` visible; require separate authorization before any GitHub settings mutation |

## Operational order

```text
PRELAUNCH_PASSWORDLESS_TEST_SMOKE = COMPLETE
-> FIRST-REAL-PLAN-C-VERTICAL-SLICE
-> RECORD_OBSERVED_OPERATIONAL_PAIN
```

No feature-development item belongs in this queue unless it is a proven Real Pilot blocker or observed operational pain.

## Current GitHub snapshot

```text
canonical main:
  36fe230a12e3d24a7bcb8c0333f3ec15012c029e

Real Pilot branch:
  codex/test-runtime-vertical-slice

implementation candidate:
  987eba04a1dc9073be6c02631792808debc35635
  ahead of main: 2
  behind main: 0

passwordless TEST candidate:
  c9c5493468757643269178f7fac3353b14b90ad5
  deployed exact SHA: YES

PR #18:
  title: feat: add transactional pilot provisioning
  state: MERGED / CLOSED
  historical merge commit: 00a029c9a3dcd2122a958514e845334d0a295ac9
  post-merge TEST candidate: c9c5493468757643269178f7fac3353b14b90ad5
  merged: true
```

PR #18 is historical; the passwordless candidate is a post-merge TEST-only descendant on the same target branch. The prior implementation candidate identity remains `987eba04a1dc9073be6c02631792808debc35635`.

## Closed execution evidence

| Evidence | Status | Receipt |
| --- | --- | --- |
| Core Safety | `COMPLETE` | No open Core Safety P0; PR #12 merged/closed |
| TEST runtime | `READY` | Ubuntu 24.04; PHP 8.4; Laravel 13; PostgreSQL 16.14; `btree_gist` 1.7; 19 migrations ran / 0 pending; `/up=200` |
| Runtime services | `PASS` | Nginx, PHP-FPM, PostgreSQL, and Scheduler active |
| DR04 implementation | `COMPLETE` | Transactional `pilot:provision` implementation and tests at the bounded candidate |
| Synthetic provisioning | `PASS` | First provision `CREATED`; exact rerun `UNCHANGED` |
| Drift and rollback | `PASS` | Configuration drift fail-closed with zero write; late failure rollback PASS |
| Synthetic Vertical Slice | `COMPLETE` | Login through Audit PASS; Early Complete fail-closed; cross-org Audit leakage `0` |
| PostgreSQL backup | `PASS` | TEST backup proof complete |
| PostgreSQL restore | `PASS` | Clean restore proof complete |
| Deployment rollback | `PASS` | TEST rollback proof complete |
| Scheduler | `PASS` | Recurring `holds:expire` proof complete |
| Plan C input / former DR17 | `COMPLETE` | Owner-approved Organization, Boat, Slots, TTL, Operator, and permission configuration supplied |
| `VERIFIED` observed-pain fix | `COMPLETE` | Commit `987eba04a1dc9073be6c02631792808debc35635`; only `PilotManifest.php` and `PilotProvisioningTest.php` changed |
| Candidate tests | `PASS` | Pint PASS; PilotProvisioning 7 tests / 39 assertions; full PHP suite 283 tests / 3293 assertions; GitHub CI SUCCESS |
| Passwordless implementation tests | `PASS` | GET `/operator/login` creates a normal Auth session, redirects by `firstGrantedRoute()`, reaches Calendar, preserves membership permissions, fails closed when ambiguous, and honors the disable flag |
| Passwordless CI | `SUCCESS` | Exact candidate `c9c5493468757643269178f7fac3353b14b90ad5`; Quality and contracts plus PostgreSQL concurrency jobs passed |
| TEST exact deployment | `PASS` | Source SHA `c9c5493468757643269178f7fac3353b14b90ad5`; `/up=200`; `PRELAUNCH_PASSWORDLESS=true`; `/operator/login` and `/operator/calendar` smoke PASS |
| Business-data preservation | `PASS` | Existing users, organizations, memberships, boats, slots, inquiries, holds, bookings, allocations, blocks, and audit rows unchanged |
| Status synchronization | `COMPLETE` | `PLANC-20260812-TES-CAO` remains `CONFIRMED / PLANNED / EXECUTION=UNKNOWN`; no historical fill |

## Approved Plan C configuration

```text
Organization: Ayany Boat Operations
Timezone: Asia/Bangkok
Boat: Plan C
Buffer: 30 / 30 minutes
HOLD TTL: 30 minutes
Operator: Cao <cao@mukuy.com>
Permissions: calendar=true, booking_workflow=true, block=true

PLAN-C-FISH-4H-AM  09:00-13:00  240  VERIFIED
PLAN-C-FISH-4H-PM  14:00-18:00  240  VERIFIED
PLAN-C-FISH-6H     12:00-18:00  360  VERIFIED
PLAN-C-FISH-8H     10:00-18:00  480  VERIFIED

applicable Boat: Plan C
compatibility: []
configuration: READY
provisioning: COMPLETE (existing TEST configuration)
Operator secret: NOT_REQUIRED_FOR_PASSWORDLESS_TEST
Cao login smoke: PASS_PASSWORDLESS
PRELAUNCH_PASSWORDLESS: ENABLED (TEST)
TEST deployed SHA: c9c5493468757643269178f7fac3353b14b90ad5
Business data changed: NO
```

## First real order policy

```text
HISTORICAL_PLAN_C_MIGRATION = NO
FIRST_REAL_VERTICAL_SLICE = NEXT_REAL_PLAN_C_ORDER
```

After the passwordless Cao login/calendar smoke, the next genuine Plan C order follows:

```text
Inquiry -> HOLD -> Confirm -> Prepare -> Depart -> Return -> Complete -> Audit
```

Do not import historical orders and do not create synthetic or invented “real” orders.

## Real Pilot status synchronization

```text
PLANC-20260812-TES-CAO = CONFIRMED / PLANNED / EXECUTION=UNKNOWN
PLANC-20260823-TEST = CONFIRMED_WAITING_PREPARATION
REAL-PILOT-TIMEZONE-VERIFY = PASS_TIMEZONE_NORMALIZATION_CORRECT
PRELAUNCH_PASSWORDLESS = ENABLED (TEST)
DECISION = ACCEPT_EXECUTION_UNKNOWN_NO_HISTORICAL_FILL
```

The UNKNOWN execution state is accepted as supplied. No historical execution is inferred, and no synthetic or invented real order is created.

## DR16 parallel boundary

```text
main.protected = false
repository rulesets = 0
status = PARALLEL_BEFORE_CUTOVER
current_real_pilot_blocker = false
mutation_authorized = false
```

DR16 remains visible but is not in the current Real Pilot critical path.

## Deferred work

The following are not active queue items: Admin UI, setup wizard, capacity/seat inventory, Product engine, CRM, Finance expansion, reporting, maintenance, historical migration, ChannelHub, OTA, second-company onboarding, and SaaS administration.

## Authority boundaries

```text
REAL_PILOT = AUTHORIZED
TEST_ONLY = true
REAL_OPERATOR_USE = AUTHORIZED
REAL_PILOT_CONFIGURATION = AUTHORIZED
PRELAUNCH_PASSWORDLESS = ENABLED (TEST)
APPLICATION_PASSWORD_REQUIRED = NO
INFRASTRUCTURE_AUTH_CHANGED = NO
BUSINESS_DATA_CHANGED = NO
EXISTING_REAL_ORDERS_CHANGED = NO

PRODUCTION_DEPLOYMENT = false
CUTOVER = false
AUTHORITY_SWITCH = false
TAG = false
RELEASE = false
```

`merge != deploy != cutover != release`
