# BoatOps Current Gate

Updated: 2026-08-15 11:07 Asia/Bangkok

## Current decision

```text
REAL_PILOT_EXECUTION
CORE_SAFETY_COMPLETE
TEST_RUNTIME_READY
SYNTHETIC_VERTICAL_SLICE_COMPLETE
REAL_PILOT_AUTHORIZED
PLAN_C_CONFIGURATION_READY
TEST_REAL_PILOT_DEPLOYED
PLAN_C_PROVISIONING_COMPLETE
AUTHENTICATED_OPERATOR_ACCESS_PROVEN
PR_18_MERGED
CAL_UX_001_INTEGRATION_COMPLETE
CAL_UX_002_MERGED_TEST_DEPLOYED
CAL_UX_003_MERGED_TEST_DEPLOYED
TODAY_OPERATIONS_V1_MERGED_TEST_DEPLOYED_VERIFIED
MAIN_TEST_SYNCHRONIZED
ENGINEERING_STOP
NO_NEW_FEATURE_DEVELOPMENT
```

BoatOps has left the active Deployment Readiness / governance-planning phase. CAL-UX-002 and CAL-UX-003 are merged, deployed to TEST, and closed as engineering items. Today Operations V1 is also merged, deployed to TEST, and verified on the real PostgreSQL runtime. Engineering is stopped; the next input is Owner real-use feedback or the next genuine Plan C order. No CAL-UX-004 exists and no global new-feature package is open.

The exact machine-readable state is in `CURRENT_STATE.yaml`; the small operational queue is in `REVIEW_QUEUE.md`.

`merge != deploy != cutover != release`

## Exact GitHub identity

```text
live main observed:
  sha: 6d739fccab4de69f511663e130c1e2308e483afb
  source: GitHub refs/heads/main
  reviewed / accepted: YES
  currently open deploy authorization: NO
  deployed to TEST: YES

verified CAL-UX-003 historical TEST baseline:
  sha: 2f59fb67ab8eea830ef6f8860ed0ee8a2acd9aa7

current TEST deployed source:
  sha: 6d739fccab4de69f511663e130c1e2308e483afb
  main ahead of TEST: false
  main / TEST relation: SYNCHRONIZED
  deployment drift: false

verified ancestry at current TEST source:
  PR #18 included: YES
  PR #19 included: YES
  Real Pilot candidate ancestry included: YES
  CAL-UX-001 implementation ancestry included: YES
  CAL-UX-002 implementation ancestry included: YES
  CAL-UX-003 implementation ancestry included: YES
  Today Operations V1 included: YES

former Real Pilot branch:
  codex/test-runtime-vertical-slice
  MERGED / HISTORICAL

immutable Real Pilot implementation candidate:
  987eba04a1dc9073be6c02631792808debc35635
  included in main ancestry: YES

PR #18:
  MERGED / CLOSED
  head: 71d2da4cfaa28c9fe8ecc31d7925d004c89e9236
  merge commit: 00a029c9a3dcd2122a958514e845334d0a295ac9

PR #19:
  MERGED / CLOSED
  base: main @ 82494d85bc2d918359d610932ae01869a29839e8
  head: codex/cal-ux-001 @ fe05d92a9534b8e2dac2f4b6af4c6161ec4c4afa
  merge commit: 77db16f16617ddcbb09ebf66d83a65a0c97695e5

PR #24:
  MERGED / CLOSED
  Issue #23: CLOSED
  merge commit: 44cdb261ce9ec981e948decfceda916c8eca2984

PR #25:
  MERGED / CLOSED
  Issue #26: CLOSED
  merge commit: 2f59fb67ab8eea830ef6f8860ed0ee8a2acd9aa7

Today Operations:
  BOATOPS-CORE-001: DONE / VERIFIED
  implementation: f5d6785e586d039072a80cc1a67b5d12c9d8b4dd
  BOATOPS-CORE-002: DONE / VERIFIED
  merge / current TEST: 6d739fccab4de69f511663e130c1e2308e483afb
```

`a2c1c69086eae9cad355c9ea4a6e962d203c177c` is historical evidence for the earlier Chinese UI TEST state. `2f59fb67ab8eea830ef6f8860ed0ee8a2acd9aa7` remains the historical CAL-UX-003 TEST baseline and merge commit. Neither is the current main or current TEST identity. `c176b91530019f47145947e63fe5929880d2ff37` remains only the historical CAL-UX-002 authoring base. `LIVE_BRANCH_REF_IS_EXTERNAL_STATE` remains in force.

## TEST runtime

`http://43.156.151.62:8080` is the selected TEST-only runtime.

Verified state:

- Ubuntu 24.04, PHP 8.4, Laravel 13;
- PostgreSQL 16.14 with `btree_gist` 1.7;
- migrations: 19 ran / 0 pending;
- `/up`: HTTP 200;
- Nginx, PHP-FPM, PostgreSQL, and Scheduler: active;
- PostgreSQL backup, restore proof, rollback proof, and `holds:expire` scheduler proof: PASS;
- existing Docker services and public `:80`: untouched;
- deployed source: `6d739fccab4de69f511663e130c1e2308e483afb`;
- main / TEST source relation: `SYNCHRONIZED`;
- the immutable Real Pilot candidate `987eba04a1dc9073be6c02631792808debc35635` is included in deployed ancestry, not deployed as a bare SHA;
- CAL-UX-001, CAL-UX-002, and CAL-UX-003 Calendar source is present on TEST;
- Today Operations V1 source is present on TEST;
- unauthenticated Calendar boundary: PASS;
- authenticated Operator access through the approved existing Cao credential path: PASS; no secret is recorded;
- Plan C Calendar read-model smoke: PASS;
- Today Operations `/operator/today`: HTTP 200 on real PostgreSQL;
- Today desktop 1440 and mobile 390 browser validation: PASS; console/page errors 0;
- Today runtime had no genuine current-day Trip, so the real runtime proof covered the PostgreSQL query and empty-state path without creating fake orders;
- inventory/business-data zero-write proof: PASS.

This makes the TEST runtime `READY` with the verified Real Pilot, CAL-UX-001/002/003, Chinese UI, and Today Operations ancestry deployed.

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

Verified TEST execution state:

```text
TEST source = 6d739fccab4de69f511663e130c1e2308e483afb
Real Pilot candidate included in deployed ancestry = true
Plan C provisioning first execution = UNCHANGED
Plan C provisioning exact rerun = UNCHANGED
Operator identity = Cao exists uniquely with approved membership
AUTHENTICATED_OPERATOR_ACCESS = PASS / APPROVED EXISTING CAO CREDENTIAL PATH
SECRET_RECORDED = false
allocations / holds / bookings / blocks = UNCHANGED
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

## CAL-UX-001 authorization and review state

Owner authorization is recorded as:

```text
classification = OBSERVED_OPERATOR_CALENDAR_USABILITY_PAIN
owner authorization = GRANTED
code review = ACCEPTED
owner merge authorization = GRANTED_AND_CONSUMED
implementation = fe05d92a9534b8e2dac2f4b6af4c6161ec4c4afa
PR #19 = MERGED / CLOSED
merge commit = 77db16f16617ddcbb09ebf66d83a65a0c97695e5
integration = COMPLETE
scope = UI + EXISTING READ PATH ONLY
inventory authority changed = false
SlotCalendarReadModel changed = false
schema / migrations changed = false
application inventory actions changed = false
deployment = TEST_ONLY_DEPLOYED
historical integration TEST source = 2f59fb67ab8eea830ef6f8860ed0ee8a2acd9aa7
Fleet Inventory source present = true
unauthenticated Calendar boundary = PASS
tag = NOT_AUTHORIZED
release = NOT_AUTHORIZED
```

CAL-UX-001 was an authorized narrow exception under `OBSERVED_OPERATIONAL_PAIN`; its integration is complete and it does not reopen feature development globally. Merge authorization has been consumed and does not authorize Deployment, Tag, Release, Cutover, or an authority switch.

## CAL-UX-002 completed integration

```text
classification = OBSERVED_OPERATIONAL_PAIN
source = OWNER_TEST_CALENDAR_REVIEW
Issue #23 = CLOSED
PR #24 = MERGED / CLOSED
historical authoring base = c176b91530019f47145947e63fe5929880d2ff37
merge commit = 44cdb261ce9ec981e948decfceda916c8eca2984
state = MERGED / TEST DEPLOYED
scope = CHINESE-FIRST + QUIET AVAILABLE / EXCEPTION-FIRST PRESENTATION
inventory authority changed = false
SlotCalendarReadModel changed = false
schema / migrations changed = false
application inventory actions changed = false
local validation = PASS
desktop 1440 visual proof = PASS
mobile 390 viewport proof = PASS
historical integration TEST source = 2f59fb67ab8eea830ef6f8860ed0ee8a2acd9aa7
production deployment = false
```

CAL-UX-002 is closed and no longer belongs to the active engineering queue. It keeps internal inventory states and the existing read/command paths unchanged.

## CAL-UX-003 completed integration

```text
classification = OBSERVED_OPERATIONAL_PAIN
source = OWNER_REAL_TEST_USE
Issue #26 = CLOSED
PR #25 = MERGED / CLOSED
merge commit = 2f59fb67ab8eea830ef6f8860ed0ee8a2acd9aa7
state = MERGED / TEST DEPLOYED
scope = DURATION-FIRST INQUIRY ENTRY
inventory authority changed = false
SlotCalendarReadModel changed = false
schema / migrations changed = false
application inventory actions changed = false
historical integration TEST source = 2f59fb67ab8eea830ef6f8860ed0ee8a2acd9aa7
production deployment = false
```

CAL-UX-003 is closed and no longer belongs to the active engineering queue. No CAL-UX-004 exists.

## Today Operations V1 completed integration

```text
BOATOPS-CORE-001 = DONE / VERIFIED
BOATOPS-CORE-002 = DONE / VERIFIED
implementation = f5d6785e586d039072a80cc1a67b5d12c9d8b4dd
merge commit = 6d739fccab4de69f511663e130c1e2308e483afb
state = MERGED / TEST DEPLOYED / VERIFIED
scope = EXISTING SSOT READ VIEW ONLY
PostgreSQL runtime = PASS
empty-state runtime = PASS
desktop browser = PASS
mobile 390 browser = PASS
business data changed = false
schema changed = false
API contract changed = false
status enum changed = false
business flow changed = false
production deployment = false
```

Today Operations V1 is closed and no longer belongs to the active engineering queue. The current TEST date had no genuine Trip, so non-empty cards remain covered by automated tests until the next genuine operating day supplies a real non-empty runtime path.

## Remaining gate prerequisite

```text
PLAN_C_REAL_CONFIG = READY
TEST_DEPLOYED_SOURCE = 6d739fccab4de69f511663e130c1e2308e483afb
MAIN_TEST_RELATION = SYNCHRONIZED
REAL_PILOT_CANDIDATE_IN_DEPLOYED_ANCESTRY = true
PLAN_C_PROVISIONING = COMPLETE
AUTHENTICATED_OPERATOR_ACCESS = PASS_APPROVED_EXISTING_CAO_CREDENTIAL_PATH
TODAY_OPERATIONS_V1 = COMPLETE_MERGED_TEST_DEPLOYED_VERIFIED
FIRST_REAL_PLAN_C_ORDER = WAITING_FOR_NEXT_GENUINE_ORDER
NO_ACTIVE_ENGINEERING_BLOCKER = true
ENGINEERING = STOP
```

Authenticated Operator access has been demonstrated through the approved existing Cao credential path. No password or secret is recorded here. The remaining operational trigger is Owner feedback or the next genuine Plan C order.

## Next operational path

No further engineering is required now. Owner real-use feedback may identify later observed pain; otherwise the next operational path begins with the next genuine Plan C order:

```text
OWNER_REAL_USE_FEEDBACK OR WAIT_FOR_NEXT_GENUINE_PLAN_C_ORDER
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

Historical Plan C orders will not be migrated. Do not create a synthetic or invented “real” order.

CAL-UX-001 integration is complete and does not replace or alter the Real Pilot operational path:

```text
PR #19 = MERGED / CLOSED
implementation fe05d92a9534b8e2dac2f4b6af4c6161ec4c4afa = INCLUDED IN MAIN ANCESTRY
merge commit = 77db16f16617ddcbb09ebf66d83a65a0c97695e5
deployment = TEST_ONLY_DEPLOYED
historical integration test source = 2f59fb67ab8eea830ef6f8860ed0ee8a2acd9aa7
Fleet Inventory source present = true
unauthenticated Calendar boundary = PASS
```

## Development rule

```text
NO_NEW_FEATURE_DEVELOPMENT
UNLESS:
  - REAL_PILOT_BLOCKER
  - OBSERVED_OPERATIONAL_PAIN
  - UNIVERSAL_CORE_SAFETY_DEFECT
```

Admin UI, setup wizard, capacity/seat inventory, Product engine, CRM, Finance expansion, reporting, maintenance, historical migration, ChannelHub, OTA, second-company onboarding, and SaaS administration remain deferred.

Routine progress does not require a governance-only PR. This bounded SSOT repair is the explicitly authorized exception; CAL-UX-002, CAL-UX-003, and Today Operations V1 are complete and do not create an active engineering item.

## Parallel items

DR16 remains `PARALLEL_BEFORE_CUTOVER / NOT_CURRENT_REAL_PILOT_BLOCKER`.

```text
main.protected = false
repository rulesets = 0
DR16 mutation authorized = false
```

This task does not change GitHub settings.

CAL-UX-001 is `CODE_REVIEW_ACCEPTED / OWNER_MERGE_AUTHORIZATION_GRANTED_AND_CONSUMED / PR19_MERGED_CLOSED / INTEGRATION_COMPLETE`. The branch `codex/cal-ux-001` is historical and no longer active implementation authority.

CAL-UX-002 and CAL-UX-003 are `MERGED / CLOSED / TEST DEPLOYED`; Issues #23 and #26 are closed. Neither is active implementation or deployment authority.

Today Operations V1 is `DONE / VERIFIED / MERGED / TEST DEPLOYED`; BOATOPS-CORE-001 and BOATOPS-CORE-002 are closed historical tasks.

## Explicit boundaries

```text
PRODUCTION_DEPLOYMENT = false
CUTOVER = false
AUTHORITY_SWITCH = false
PR_19 = MERGED_CLOSED
CAL_UX_001_TEST_DEPLOYMENT = VERIFIED
CAL_UX_002_TEST_DEPLOYMENT = VERIFIED
CAL_UX_003_TEST_DEPLOYMENT = VERIFIED
TODAY_OPERATIONS_V1_TEST_DEPLOYMENT = VERIFIED
LIVE_MAIN_VS_TEST_TRUTH = SYNCHRONIZED
CAL_UX_003_SOURCE_ACCURACY = PASS
CAL_UX_001_PRODUCTION_DEPLOYMENT = false
TAG = false
RELEASE = false
```

This governance-only synchronization changes no runtime behavior. It records the already verified CAL-UX-002/003 history, Today Operations merge/TEST deployment, authenticated Cao access, and current main/TEST identity; it records no password or secret and executes no deployment, provisioning, production change, real-data migration, cutover, tag, or release.

## Closed history

Core Safety and the prior Deployment Readiness planning work remain accepted history, not active queue items. PR #12, PR #15, PR #16, PR #18, PR #19, PR #24, and PR #25 are merged/closed. CAL-UX-002 and CAL-UX-003 are deployed to TEST. BOATOPS-CORE-001 and BOATOPS-CORE-002 are DONE / VERIFIED. The system awaits Owner real-use feedback or the next genuine Plan C order, not further engineering.
