# BoatOps Current Gate

Updated: 2026-08-16 18:33 Asia/Bangkok

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
MAIN_HAS_UNDEPLOYED_EXTERNAL_DELTA
INQ_OPS_001_MERGED_NOT_TEST_DEPLOYED
TEST_DEPLOY_PRE_CHECK_PENDING
ENGINEERING_STOP_AT_MERGED_NOT_TEST_DEPLOYED
NO_GLOBAL_NEW_FEATURE_DEVELOPMENT
```

Owner real-use feedback produced the bounded `OBSERVED_OPERATIONAL_PAIN` contract in Issue #28. INQ-OPS-001 was implemented, reviewed, and merged to `main` via PR #30 at `46b3d9f4fe239c933f6c2e2e32c0449100b7faf0`. It is not TEST-deployed, and it grants no TEST deployment, Production, Cutover, Tag, or Release authority. No CAL-UX-004 exists and no global new-feature package is open.

The exact machine-readable state is in `CURRENT_STATE.yaml`; the small operational queue is in `REVIEW_QUEUE.md`.

`merge != deploy != cutover != release`

## Exact GitHub identity

```text
live main observed:
  sha: 46b3d9f4fe239c933f6c2e2e32c0449100b7faf0
  source: GitHub refs/heads/main
  reviewed / accepted: REVIEWED_FOR_SCOPE / NOT_ACCEPTED_AS_TEST_BASELINE
  currently open deploy authorization: NO
  deployed to TEST: NO (PR #30 INQ-OPS-001 code delta and PR #31 docs-only delta remain external to TEST)

verified CAL-UX-003 historical TEST baseline:
  sha: 2f59fb67ab8eea830ef6f8860ed0ee8a2acd9aa7

current TEST deployed source:
  sha: 6d739fccab4de69f511663e130c1e2308e483afb
  main ahead of TEST: true
  main / TEST relation: MAIN_HAS_UNDEPLOYED_EXTERNAL_DELTA
  deployment drift: true

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

`a2c1c69086eae9cad355c9ea4a6e962d203c177c` is historical evidence for the earlier Chinese UI TEST state. `2f59fb67ab8eea830ef6f8860ed0ee8a2acd9aa7` remains the historical CAL-UX-003 TEST baseline and merge commit. Neither is the current main or current TEST identity. `c176b91530019f47145947e63fe5929880d2ff37` remains only the historical CAL-UX-002 authoring base. Current live main is `46b3d9f4fe239c933f6c2e2e32c0449100b7faf0`; current TEST remains `6d739fccab4de69f511663e130c1e2308e483afb`, with PR #30's INQ-OPS-001 code and PR #31's docs-only delta not deployed. `LIVE_BRANCH_REF_IS_EXTERNAL_STATE` remains in force.

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
- main / TEST source relation: `MAIN_HAS_UNDEPLOYED_EXTERNAL_DELTA`;
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

## INQ-OPS-001 merged, not TEST-deployed

```text
classification = OBSERVED_OPERATIONAL_PAIN
source = OWNER_REAL_USE_FEEDBACK
Issue #28 = AUTHORITATIVE CONTRACT / CLOSED
baseline main = 5bbb1ae75a1e40ec09dc8fa9a052e20c40eec38b
branch = feat/inq-ops-001-operational-dossier-v1
starting head = 8895d2c6f0c91b7c12188b93284e5f5586cd2153
implementation = b8daba01fc3f2157d5d5b5ee862bac0a5575deab
origin/main at validation = 12d85ced7e6568b7992f12841264bb01ea8ee765
PR #30 = MERGED / CLOSED
merge commit = 46b3d9f4fe239c933f6c2e2e32c0449100b7faf0
state = MERGED / NOT TEST DEPLOYED
R1 review fixes = PASS
scope = INQUIRY OPERATIONAL DOSSIER V1
Inquiry remains operational-dossier SSOT = true
proven execution-gap fields added = 8
schema changed = true
inventory authority changed = false
SlotCalendarReadModel changed = false
HOLD conflict logic changed = false
Booking lifecycle changed = false
Trip lifecycle changed = false
organization isolation preserved = true
real data changed = false
owner merge authorization = GRANTED_AND_CONSUMED
merge authorized = false
TEST deployment authorized = false
TEST deployed = false
Production touched = false
```

The merged work keeps `Inquiry reference -> HOLD external_reference` and the existing `Inquiry -> HOLD -> Booking -> Trip` path. Room number remains optional/later-fillable, pickup time remains separate from the selected Slot service interval, and selling amounts remain minor-unit integers behind decimal operator input. Merge is complete; TEST deployment precheck and separate authorization are the next gates; this executor does not self-final-accept.

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
LIVE_MAIN_OBSERVED = 46b3d9f4fe239c933f6c2e2e32c0449100b7faf0
MAIN_TEST_RELATION = MAIN_HAS_UNDEPLOYED_EXTERNAL_DELTA
REAL_PILOT_CANDIDATE_IN_DEPLOYED_ANCESTRY = true
PLAN_C_PROVISIONING = COMPLETE
AUTHENTICATED_OPERATOR_ACCESS = PASS_APPROVED_EXISTING_CAO_CREDENTIAL_PATH
TODAY_OPERATIONS_V1 = COMPLETE_MERGED_TEST_DEPLOYED_VERIFIED
FIRST_REAL_PLAN_C_ORDER = WAITING_FOR_NEXT_GENUINE_ORDER
INQ_OPS_001 = MERGED_NOT_TEST_DEPLOYED
INQ_OPS_001_CONTROL_PLANE_REVIEW = COMPLETE_MERGED
NO_ACTIVE_ENGINEERING_BLOCKER = true
ENGINEERING = STOP_AT_MERGED_NOT_TEST_DEPLOYED
```

Authenticated Operator access has been demonstrated through the approved existing Cao credential path. No password or secret is recorded here. INQ-OPS-001 is merged at 46b3d9f; the next gate is TEST deployment precheck, and any TEST deployment remains a separate Owner decision.

## Next operational path

No further INQ-OPS-001 implementation is authorized. The next gate sequence is:

```text
TEST_DEPLOY_PRE_CHECK
-> SEPARATE_TEST_DEPLOY_AUTHORIZATION_IF_APPROVED
```

The genuine operational path remains `Inquiry -> HOLD -> Confirm -> Prepare -> Depart -> Return -> Complete -> Audit` after separately authorized integration/deployment. Historical Plan C orders will not be migrated. Do not create a synthetic or invented “real” order.

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

Routine progress does not require a governance-only PR. INQ-OPS-001 was the authorized bounded implementation exception from Owner real-use feedback; this post-merge state sync is the Control-Plane-directed update. CAL-UX-002, CAL-UX-003, and Today Operations V1 remain completed history.

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

INQ-OPS-001 is `MERGED / NOT TEST DEPLOYED`; it is not TEST-deployed and creates no authority for adjacent Inquiry, Booking, Calendar, Today Operations, CRM, finance, or inventory work.

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
INQ_OPS_001 = MERGED_NOT_TEST_DEPLOYED
INQ_OPS_001_OWNER_MERGE_AUTHORIZATION = GRANTED_AND_CONSUMED
INQ_OPS_001_MERGE_AUTHORIZED = false
INQ_OPS_001_TEST_DEPLOYMENT_AUTHORIZED = false
INQ_OPS_001_TEST_DEPLOYED = false
INQ_OPS_001_PRODUCTION_TOUCHED = false
LIVE_MAIN_VS_TEST_TRUTH = MAIN_HAS_UNDEPLOYED_EXTERNAL_DELTA
CAL_UX_003_SOURCE_ACCURACY = PASS
CAL_UX_001_PRODUCTION_DEPLOYMENT = false
TAG = false
RELEASE = false
```

This bounded implementation candidate changes the existing Inquiry schema and operator UI only. It records no password, secret, or real booking data and executes no deployment, provisioning, production change, real-data migration, cutover, tag, or release.

## Closed history

Core Safety and the prior Deployment Readiness planning work remain accepted history, not active queue items. PR #12, PR #15, PR #16, PR #18, PR #19, PR #24, and PR #25 are merged/closed. CAL-UX-002 and CAL-UX-003 are deployed to TEST. BOATOPS-CORE-001 and BOATOPS-CORE-002 are DONE / VERIFIED. INQ-OPS-001 is merged via PR #30 at 46b3d9f; no TEST deployment is implied.
