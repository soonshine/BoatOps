# BoatOps Operational Queue and Evidence Ledger

Last updated: 2026-08-14 19:07 Asia/Bangkok

The active operational queue contains Owner real-use feedback and the next genuine Plan C order. Authenticated Operator access has already been demonstrated through the approved existing Cao credential path; no credential or secret is recorded here. CAL-UX-001/002/003 and the TEST reconciliation are completed evidence, not active engineering items.

## Active queue

| ID | Status | Next proof |
| --- | --- | --- |
| `OWNER-REAL-USE-FEEDBACK` | `WAITING / NO_ENGINEERING` | Record only feedback observed through real TEST use; do not pre-create CAL-UX-004 |
| `FIRST-REAL-PLAN-C-VERTICAL-SLICE` | `WAITING / NEXT_GENUINE_ORDER` | Run the next genuine Plan C order from Inquiry through Audit; do not invent an order |
| `DR16` | `PARALLEL_BEFORE_CUTOVER / NOT_CURRENT_REAL_PILOT_BLOCKER` | Keep `main.protected=false` visible; require separate authorization before any GitHub settings mutation |

## Operational order

```text
OWNER-REAL-USE-FEEDBACK OR WAIT-FOR-NEXT-GENUINE-PLAN-C-ORDER
-> FIRST-REAL-PLAN-C-VERTICAL-SLICE
-> RECORD_OBSERVED_OPERATIONAL_PAIN
```

No feature-development item belongs in this queue unless it is a proven Real Pilot blocker or observed operational pain.

## Completed Calendar UX integrations

CAL-UX-002 and CAL-UX-003 addressed Owner-observed TEST Calendar pain. Both are closed and do not replace the Real Pilot operational order above.

```text
CAL-UX-002:
  classification = OBSERVED_OPERATIONAL_PAIN
  Issue #23 = CLOSED
  PR #24 = MERGED / CLOSED
  merge commit = 44cdb261ce9ec981e948decfceda916c8eca2984
  TEST deployment = VERIFIED

CAL-UX-003:
  classification = OBSERVED_OPERATIONAL_PAIN
  source = OWNER_REAL_TEST_USE
  Issue #26 = CLOSED
  PR #25 = MERGED / CLOSED
  merge commit = 2f59fb67ab8eea830ef6f8860ed0ee8a2acd9aa7
  TEST deployment = VERIFIED

current TEST source = 2f59fb67ab8eea830ef6f8860ed0ee8a2acd9aa7
inventory authority / SlotCalendarReadModel / schema / migrations / application inventory actions = UNCHANGED
active engineering item = NONE
```

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
test source = 2f59fb67ab8eea830ef6f8860ed0ee8a2acd9aa7
Fleet Inventory source present = true
unauthenticated Calendar boundary = PASS
```

## Current GitHub snapshot

```text
live main observed:
  sha: a2c1c69086eae9cad355c9ea4a6e962d203c177c
  source: GitHub refs/heads/main
  reviewed / accepted / deploy-authorized by this task: NO

verified CAL-UX-003 TEST baseline:
  sha: 2f59fb67ab8eea830ef6f8860ed0ee8a2acd9aa7
  TEST deployed source: 2f59fb67ab8eea830ef6f8860ed0ee8a2acd9aa7
  main ahead of TEST: true
  main / TEST relation: MAIN_HAS_UNDEPLOYED_EXTERNAL_DELTA

verified ancestry at the TEST baseline:
  PR #18 included: YES
  PR #19 included: YES
  PR #24 included: YES
  PR #25 included: YES

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

PR #24:
  title: CAL-UX-002 Chinese-first quiet fleet calendar
  state: MERGED / CLOSED
  Issue #23: CLOSED
  merge commit: 44cdb261ce9ec981e948decfceda916c8eca2984

PR #25:
  title: CAL-UX-003 Duration-first inquiry entry
  state: MERGED / CLOSED
  Issue #26: CLOSED
  merge commit: 2f59fb67ab8eea830ef6f8860ed0ee8a2acd9aa7
```

`c176b91530019f47145947e63fe5929880d2ff37` remains only the historical CAL-UX-002 authoring base. `LIVE_BRANCH_REF_IS_EXTERNAL_STATE`; later gates must still resolve the live GitHub ref.

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
| CAL-UX-001 / PR #19 integration | `COMPLETE / MERGED / CLOSED / TEST DEPLOYED` | Accepted implementation `fe05d92a9534b8e2dac2f4b6af4c6161ec4c4afa` is included in current TEST source `2f59fb67ab8eea830ef6f8860ed0ee8a2acd9aa7` |
| CAL-UX-002 / PR #24 integration | `COMPLETE / MERGED / CLOSED / TEST DEPLOYED` | Issue #23 closed; merge commit `44cdb261ce9ec981e948decfceda916c8eca2984`; included in current TEST source |
| CAL-UX-003 / PR #25 integration | `COMPLETE / MERGED / CLOSED / TEST DEPLOYED` | Issue #26 closed; merge commit and current TEST source `2f59fb67ab8eea830ef6f8860ed0ee8a2acd9aa7` |

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
Operator access: PASS / APPROVED EXISTING CAO CREDENTIAL PATH
Credential secret recorded: NO
Cao login smoke: PASS
Plan C read projection: PASS
inventory zero-write: PASS
allocations / holds / bookings / blocks: UNCHANGED
```

## First real order policy

```text
HISTORICAL_PLAN_C_MIGRATION = NO
FIRST_REAL_VERTICAL_SLICE = NEXT_REAL_PLAN_C_ORDER
```

Authenticated Operator access is already proven. The next genuine Plan C order follows:

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

CAL_UX_002_PR_24 = MERGED_CLOSED
CAL_UX_002_TEST_DEPLOYMENT = VERIFIED
CAL_UX_003_PR_25 = MERGED_CLOSED
CAL_UX_003_TEST_DEPLOYMENT = VERIFIED
LIVE_MAIN_VS_TEST_TRUTH = PASS
CAL_UX_003_SOURCE_ACCURACY = PASS
PR_27_MERGE = NOT_YET_AUTHORIZED
ENGINEERING = STOP
CAL_UX_004_EXISTS = false

PRODUCTION_DEPLOYMENT = false
CUTOVER = false
AUTHORITY_SWITCH = false
TAG = false
RELEASE = false
```

`merge != deploy != cutover != release`
