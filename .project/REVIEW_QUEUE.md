# BoatOps Operational Queue and Evidence Ledger

Last updated: 2026-08-12 11:20 Asia/Bangkok

The active queue is limited to unresolved Real Pilot execution work. Completed Deployment Readiness, DR04, DR17 input, synthetic runtime, backup, restore, rollback, and scheduler items are closed evidence below, not active blockers.

## Active queue

| ID | Status | Next proof |
| --- | --- | --- |
| `REAL-PILOT-DEPLOY` | `PENDING / TEST_ONLY` | Deploy the exact Real Pilot implementation candidate to TEST and record the deployed SHA plus `/up=200` |
| `PLAN-C-PROVISION` | `PENDING / OPERATOR_SECRET_NOT_CONFIGURED` | Inject the TEST-only Operator secret, validate the private manifest, prove first result `CREATED`, then exact rerun `UNCHANGED` |
| `CAO-LOGIN-SMOKE` | `PENDING / AFTER_PROVISIONING` | Login as Cao on TEST, reach `/operator/calendar`, and confirm the four Plan C Slots are visible |
| `FIRST-REAL-PLAN-C-VERTICAL-SLICE` | `PENDING / NEXT_REAL_ORDER` | After provisioning and login smoke, run the next genuine Plan C order from Inquiry through Audit; do not invent an order |
| `DR16` | `PARALLEL_BEFORE_CUTOVER / NOT_CURRENT_REAL_PILOT_BLOCKER` | Keep `main.protected=false` visible; require separate authorization before any GitHub settings mutation |

## Operational order

```text
REAL-PILOT-DEPLOY
-> PLAN-C-PROVISION
-> CAO-LOGIN-SMOKE
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

PR #18:
  title: feat: add transactional pilot provisioning
  state: OPEN
  draft: true
  mergeable_state: CLEAN
  merged: false
```

The documentation-only state-sync commit will become the live PR head after push. The implementation candidate identity remains `987eba04a1dc9073be6c02631792808debc35635`.

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
provisioning: PENDING
Operator secret: NOT_CONFIGURED
Cao login smoke: PENDING
```

## First real order policy

```text
HISTORICAL_PLAN_C_MIGRATION = NO
FIRST_REAL_VERTICAL_SLICE = NEXT_REAL_PLAN_C_ORDER
```

After provisioning and Cao login/calendar smoke, the next genuine Plan C order follows:

```text
Inquiry -> HOLD -> Confirm -> Prepare -> Depart -> Return -> Complete -> Audit
```

Do not import historical orders and do not create synthetic or invented “real” orders.

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

PRODUCTION_DEPLOYMENT = false
CUTOVER = false
AUTHORITY_SWITCH = false
TAG = false
RELEASE = false
```

`merge != deploy != cutover != release`
