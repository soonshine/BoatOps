# BoatOps Operational Queue and Evidence Ledger

Last updated: 2026-08-13 11:27 Asia/Bangkok

The primary active queue remains unresolved Real Pilot execution work. CAL-UX-001 is tracked only as a bounded parallel integration item for accepted observed operational pain. Completed Deployment Readiness, DR04, DR17 input, synthetic runtime, backup, restore, rollback, and scheduler items are closed evidence below, not active blockers.

## Active queue

| ID | Status | Next proof |
| --- | --- | --- |
| `REAL-PILOT-DEPLOY` | `PENDING / TEST_ONLY` | Deploy the exact Real Pilot implementation candidate to TEST and record the deployed SHA plus `/up=200` |
| `PLAN-C-PROVISION` | `PENDING / OPERATOR_SECRET_NOT_CONFIGURED` | Inject the TEST-only Operator secret, validate the private manifest, prove first result `CREATED`, then exact rerun `UNCHANGED` |
| `CAO-LOGIN-SMOKE` | `PENDING / AFTER_PROVISIONING` | Login as Cao on TEST, reach `/operator/calendar`, and confirm the four Plan C Slots are visible |
| `FIRST-REAL-PLAN-C-VERTICAL-SLICE` | `PENDING / NEXT_REAL_ORDER` | After provisioning and login smoke, run the next genuine Plan C order from Inquiry through Audit; do not invent an order |
| `DR16` | `PARALLEL_BEFORE_CUTOVER / NOT_CURRENT_REAL_PILOT_BLOCKER` | Keep `main.protected=false` visible; require separate authorization before any GitHub settings mutation |
| `CAL-UX-001` | `CODE_REVIEW_ACCEPTED / PR19_OPEN_DRAFT / MERGE_PENDING` | Merge governance sync; retarget PR #19 to `main`; verify only the CAL-UX-001 delta and exact-head CI; obtain a separate final merge decision |

## Operational order

```text
REAL-PILOT-DEPLOY
-> PLAN-C-PROVISION
-> CAO-LOGIN-SMOKE
-> FIRST-REAL-PLAN-C-VERTICAL-SLICE
-> RECORD_OBSERVED_OPERATIONAL_PAIN
```

No feature-development item belongs in this queue unless it is a proven Real Pilot blocker or observed operational pain.

## Bounded parallel integration

CAL-UX-001 is classified `OBSERVED_OPERATOR_CALENDAR_USABILITY_PAIN` and does not replace the Real Pilot operational order above.

```text
governance sync merged
-> retarget PR #19 from codex/test-runtime-vertical-slice to main
-> verify resulting PR contains only CAL-UX-001 implementation delta
-> verify exact-head CI
-> final merge decision
```

## Current GitHub snapshot

```text
canonical main:
  authoring baseline: 00a029c9a3dcd2122a958514e845334d0a295ac9
  live head source: GitHub refs/heads/main
  PR #18 included: YES

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
  title: CAL-UX-001: Fleet Inventory Calendar (stacked on PR #18)
  state: OPEN / DRAFT / NOT_MERGED
  head: codex/cal-ux-001 @ fe05d92a9534b8e2dac2f4b6af4c6161ec4c4afa
```

`00a029c9a3dcd2122a958514e845334d0a295ac9` is the verified authoring baseline. `LIVE_BRANCH_REF_IS_EXTERNAL_STATE`; no future governance-merge SHA is predicted here.

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

CAL_UX_001_OWNER_AUTHORIZATION = GRANTED
CAL_UX_001_CODE_REVIEW = ACCEPTED
CAL_UX_001_PR_19 = OPEN_DRAFT_NOT_MERGED
CAL_UX_001_MERGE_AUTHORIZED = false
CAL_UX_001_DEPLOYMENT_AUTHORIZED = false

PRODUCTION_DEPLOYMENT = false
CUTOVER = false
AUTHORITY_SWITCH = false
TAG = false
RELEASE = false
```

`merge != deploy != cutover != release`
