# BoatOps Operational Queue and Evidence Ledger

Last updated: 2026-08-13 17:05 Asia/Bangkok

The primary active queue now contains only the remaining authenticated Operator gate and the next genuine Plan C order. CAL-UX-001 integration and the passwordless TEST reconciliation are recorded as completed evidence. Completed Deployment Readiness, DR04, DR17 input, synthetic runtime, backup, restore, rollback, and scheduler items are closed evidence below, not active blockers.

## Active queue

| ID | Status | Next proof |
| --- | --- | --- |
| `AUTHENTICATED-OPERATOR-SMOKE` | `DEFERRED / NO_PASSWORD` | Complete later through an Owner-approved existing credential path; do not create, reset, rotate, or bypass credentials |
| `FIRST-REAL-PLAN-C-VERTICAL-SLICE` | `WAITING / NEXT_GENUINE_ORDER` | After authenticated Operator smoke, run the next genuine Plan C order from Inquiry through Audit; do not invent an order |
| `DR16` | `PARALLEL_BEFORE_CUTOVER / NOT_CURRENT_REAL_PILOT_BLOCKER` | Keep `main.protected=false` visible; require separate authorization before any GitHub settings mutation |

## Operational order

```text
AUTHENTICATED-OPERATOR-SMOKE
-> WAIT-FOR-NEXT-GENUINE-PLAN-C-ORDER
-> FIRST-REAL-PLAN-C-VERTICAL-SLICE
-> RECORD_OBSERVED_OPERATIONAL_PAIN
```

No feature-development item belongs in this queue unless it is a proven Real Pilot blocker or observed operational pain.

## Completed bounded integration

CAL-UX-001 is classified `OBSERVED_OPERATOR_CALENDAR_USABILITY_PAIN` and does not replace the Real Pilot operational order above.

```text
CODE_REVIEW = ACCEPTED
OWNER_MERGE_AUTHORIZATION = GRANTED_AND_CONSUMED
PR #19 = MERGED / CLOSED
implementation = fe05d92a9534b8e2dac2f4b6af4c6161ec4c4afa
merge commit = 77db16f16617ddcbb09ebf66d83a65a0c97695e5
integration = COMPLETE
deployment = TEST_ONLY_DEPLOYED
test source = b93846bfbdabc12fc83307392b3fa896aaf323c3
Fleet Inventory source present = true
unauthenticated Calendar boundary = PASS
```

## Current GitHub snapshot

```text
canonical main:
  authoring baseline: 77db16f16617ddcbb09ebf66d83a65a0c97695e5
  live head source: GitHub refs/heads/main
  PR #18 included: YES
  PR #19 included: YES

former Real Pilot branch:
  codex/test-runtime-vertical-slice
  status: MERGED / HISTORICAL

immutable Real Pilot implementation candidate:
  987eba04a1dc9073be6c02631792808debc35635
  included in main ancestry: YES

PR #18:
  title: feat: add transactional pilot provisioning
  state: MERGED / CLOSED
  head: 71d2da4cfaa28c9fe8ecc31d7925d004c89e9236
  merge commit: 00a029c9a3dcd2122a958514e845334d0a295ac9

PR #19:
  title: CAL-UX-001: Fleet Inventory Calendar
  state: MERGED / CLOSED
  head: codex/cal-ux-001 @ fe05d92a9534b8e2dac2f4b6af4c6161ec4c4afa
  merge commit: 77db16f16617ddcbb09ebf66d83a65a0c97695e5
```

`77db16f16617ddcbb09ebf66d83a65a0c97695e5` is the verified authoring baseline. `LIVE_BRANCH_REF_IS_EXTERNAL_STATE`; no future governance-receipt merge SHA is predicted here.

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
| PR #18 integration | `MERGED / CLOSED` | Head `71d2da4cfaa28c9fe8ecc31d7925d004c89e9236` merged into `main` as `00a029c9a3dcd2122a958514e845334d0a295ac9` |
| CAL-UX-001 / PR #19 integration | `COMPLETE / MERGED / CLOSED` | Accepted implementation `fe05d92a9534b8e2dac2f4b6af4c6161ec4c4afa` merged into `main` as `77db16f16617ddcbb09ebf66d83a65a0c97695e5`; TEST deployment verified at `b93846bfbdabc12fc83307392b3fa896aaf323c3` |

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
provisioning: COMPLETE (first execution UNCHANGED; exact rerun UNCHANGED)
Operator secret: DEFERRED / NOT_REQUIRED_FOR_CURRENT_PASSWORDLESS_VERIFICATION
Cao login smoke: DEFERRED_NO_PASSWORD
Plan C read projection: PASS
inventory zero-write: PASS
allocations / holds / bookings / blocks: UNCHANGED
```

## First real order policy

```text
HISTORICAL_PLAN_C_MIGRATION = NO
FIRST_REAL_VERTICAL_SLICE = NEXT_REAL_PLAN_C_ORDER
```

After authenticated Operator smoke, the next genuine Plan C order follows:

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

CAL_UX_001_OWNER_AUTHORIZATION = GRANTED
CAL_UX_001_CODE_REVIEW = ACCEPTED
CAL_UX_001_OWNER_MERGE_AUTHORIZATION = GRANTED_AND_CONSUMED
CAL_UX_001_PR_19 = MERGED_CLOSED
CAL_UX_001_INTEGRATION = COMPLETE
CAL_UX_001_TEST_DEPLOYMENT = VERIFIED
CAL_UX_001_PRODUCTION_DEPLOYMENT = false
CAL_UX_001_TAG = NOT_AUTHORIZED
CAL_UX_001_RELEASE = NOT_AUTHORIZED

PRODUCTION_DEPLOYMENT = false
CUTOVER = false
AUTHORITY_SWITCH = false
TAG = false
RELEASE = false
```

`merge != deploy != cutover != release`
