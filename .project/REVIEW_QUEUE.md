# BoatOps Operational Queue and Evidence Ledger

Last updated: 2026-08-15 11:07 Asia/Bangkok

The active operational queue contains Owner real-use feedback and the next genuine Plan C order. Authenticated Operator access has already been demonstrated through the approved existing Cao credential path; no credential or secret is recorded here. CAL-UX-001/002/003 and Today Operations V1 are completed evidence, not active engineering items.

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

No feature-development item belongs in this queue unless it is a proven Real Pilot blocker, observed operational pain, or universal core safety defect.

## Current GitHub / TEST snapshot

```text
current main:
  sha: 6d739fccab4de69f511663e130c1e2308e483afb
  source: GitHub refs/heads/main

current TEST deployed source:
  sha: 6d739fccab4de69f511663e130c1e2308e483afb
  environment: http://43.156.151.62:8080

main ahead of TEST: false
main / TEST relation: SYNCHRONIZED
deployment drift: false
undeployed main delta: false
active engineering item: NONE
```

Historical identities remain evidence only:

```text
CAL-UX-003 historical TEST baseline / merge commit = 2f59fb67ab8eea830ef6f8860ed0ee8a2acd9aa7
Chinese UI TEST source before Today deployment = a2c1c69086eae9cad355c9ea4a6e962d203c177c
Real Pilot immutable candidate = 987eba04a1dc9073be6c02631792808debc35635
CAL-UX-002 historical authoring base = c176b91530019f47145947e63fe5929880d2ff37
```

None of those historical SHAs is the current main or current TEST identity.

## Completed Today Operations V1

```text
BOATOPS-CORE-001 = DONE / VERIFIED
BOATOPS-CORE-002 = DONE / VERIFIED
implementation commit = f5d6785e586d039072a80cc1a67b5d12c9d8b4dd
merge commit = 6d739fccab4de69f511663e130c1e2308e483afb
merged to main = YES
TEST deployment = VERIFIED
TEST source = 6d739fccab4de69f511663e130c1e2308e483afb
PostgreSQL runtime = PASS
/operator/today = HTTP 200
Chinese Today page = PASS
empty-state runtime = PASS
desktop 1440 browser = PASS
mobile 390 browser = PASS
horizontal overflow = false
browser console errors = 0
page errors = 0
business data changed = NO
DB schema changed = NO
API contract changed = NO
status enum changed = NO
business flow changed = NO
Production touched = NO
```

The TEST runtime had zero genuine trips for the current day. Runtime verification therefore exercised the real PostgreSQL query and empty-state path without creating fake orders. Non-empty task cards, attention rules, timezone boundaries, organization isolation, and detail links are covered by automated tests.

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

current TEST source = 6d739fccab4de69f511663e130c1e2308e483afb
inventory authority / SlotCalendarReadModel / schema / migrations / application inventory actions = UNCHANGED
active engineering item = NONE
```

## Completed CAL-UX-001 integration

CAL-UX-001 is classified `OBSERVED_OPERATOR_CALENDAR_USABILITY_PAIN` and remains accepted historical evidence.

```text
CODE_REVIEW = ACCEPTED
OWNER_MERGE_AUTHORIZATION = GRANTED_AND_CONSUMED
PR #19 = MERGED / CLOSED
implementation = fe05d92a9534b8e2dac2f4b6af4c6161ec4c4afa
merge commit = 77db16f16617ddcbb09ebf66d83a65a0c97695e5
integration = COMPLETE
deployment = TEST_ONLY_DEPLOYED
current TEST source = 6d739fccab4de69f511663e130c1e2308e483afb
Fleet Inventory source present = true
unauthenticated Calendar boundary = PASS
```

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
| PostgreSQL backup / restore | `PASS` | TEST backup and clean restore proof complete |
| Deployment rollback | `PASS` | TEST rollback proof complete |
| Scheduler | `PASS` | Recurring `holds:expire` proof complete |
| Plan C configuration | `COMPLETE` | Owner-approved Organization, Boat, Slots, TTL, Operator, and permission configuration supplied |
| PR #18 integration | `MERGED / CLOSED` | Real Pilot implementation ancestry preserved in current main/TEST |
| CAL-UX-001 / PR #19 | `COMPLETE / MERGED / CLOSED / TEST DEPLOYED` | Historical integration preserved; present in current TEST ancestry |
| CAL-UX-002 / PR #24 | `COMPLETE / MERGED / CLOSED / TEST DEPLOYED` | Issue #23 closed; present in current TEST ancestry |
| CAL-UX-003 / PR #25 | `COMPLETE / MERGED / CLOSED / TEST DEPLOYED` | Issue #26 closed; present in current TEST ancestry |
| BOATOPS-CORE-001 | `DONE / VERIFIED` | Today Operations V1 implementation accepted at `f5d6785e586d039072a80cc1a67b5d12c9d8b4dd` |
| BOATOPS-CORE-002 | `DONE / VERIFIED` | Today merged/deployed as main/TEST `6d739fccab4de69f511663e130c1e2308e483afb`; PostgreSQL + browser PASS |

## Approved Plan C configuration

```text
Organization: Ayany Boat Operations
Timezone: Asia/Bangkok
Boat: Plan C
Buffer: 30 / 30 minutes
HOLD TTL: 30 minutes
Operator access: approved existing Cao credential path
Credential secret recorded: NO

PLAN-C-FISH-4H-AM  09:00-13:00  240  VERIFIED
PLAN-C-FISH-4H-PM  14:00-18:00  240  VERIFIED
PLAN-C-FISH-6H     12:00-18:00  360  VERIFIED
PLAN-C-FISH-8H     10:00-18:00  480  VERIFIED

applicable Boat: Plan C
compatibility: []
configuration: READY
provisioning: COMPLETE
Plan C read projection: PASS
inventory zero-write: PASS
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

CAL_UX_001_PR_19 = MERGED_CLOSED
CAL_UX_001_TEST_DEPLOYMENT = VERIFIED
CAL_UX_002_PR_24 = MERGED_CLOSED
CAL_UX_002_TEST_DEPLOYMENT = VERIFIED
CAL_UX_003_PR_25 = MERGED_CLOSED
CAL_UX_003_TEST_DEPLOYMENT = VERIFIED

BOATOPS_CORE_001 = DONE_VERIFIED
BOATOPS_CORE_002 = DONE_VERIFIED
TODAY_OPERATIONS_V1 = MERGED_TEST_DEPLOYED_VERIFIED

CURRENT_MAIN = 6d739fccab4de69f511663e130c1e2308e483afb
CURRENT_TEST = 6d739fccab4de69f511663e130c1e2308e483afb
MAIN_TEST_RELATION = SYNCHRONIZED
DEPLOYMENT_DRIFT = false

ENGINEERING = STOP
CAL_UX_004_EXISTS = false
PRODUCTION_DEPLOYMENT = false
CUTOVER = false
AUTHORITY_SWITCH = false
TAG = false
RELEASE = false
```

`merge != deploy != cutover != release`
