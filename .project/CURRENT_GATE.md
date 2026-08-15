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
CAL_UX_001_INTEGRATION_COMPLETE
CAL_UX_002_MERGED_TEST_DEPLOYED
CAL_UX_003_MERGED_TEST_DEPLOYED
TODAY_OPERATIONS_V1_MERGED_TEST_DEPLOYED_VERIFIED
MAIN_TEST_SYNCHRONIZED
NO_DEPLOYMENT_DRIFT
BOATOPS_CORE_001_DONE_VERIFIED
BOATOPS_CORE_002_DONE_VERIFIED
ENGINEERING_STOP
NO_NEW_FEATURE_DEVELOPMENT
```

BoatOps is in real-pilot execution. The current engineering queue is empty. The next input is Owner real-use feedback or the next genuine Plan C order.

The exact machine-readable state is in `CURRENT_STATE.yaml`; the operational queue and evidence ledger are in `REVIEW_QUEUE.md`.

`merge != deploy != cutover != release`

## Current GitHub / TEST identity

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
```

The current main and TEST runtime are aligned at `6d739fccab4de69f511663e130c1e2308e483afb`.

Historical SHAs remain valid historical evidence:

- CAL-UX-003 merge / historical TEST baseline: `2f59fb67ab8eea830ef6f8860ed0ee8a2acd9aa7`;
- Chinese UI TEST source before Today Operations deployment: `a2c1c69086eae9cad355c9ea4a6e962d203c177c`;
- immutable Real Pilot implementation candidate: `987eba04a1dc9073be6c02631792808debc35635`;
- CAL-UX-002 historical authoring base: `c176b91530019f47145947e63fe5929880d2ff37`.

These historical SHAs are not current live/deployed identities.

## Today Operations V1

```text
BOATOPS-CORE-001 = DONE / VERIFIED
BOATOPS-CORE-002 = DONE / VERIFIED
implementation SHA = f5d6785e586d039072a80cc1a67b5d12c9d8b4dd
merge commit = 6d739fccab4de69f511663e130c1e2308e483afb
merged to main = YES
deployed to TEST = YES
TEST source = 6d739fccab4de69f511663e130c1e2308e483afb
PostgreSQL runtime = PASS
/operator/today HTTP = 200
Chinese Today page = PASS
empty-state runtime = PASS
desktop 1440 browser = PASS
mobile 390 browser = PASS
browser console errors = 0
business data changed = false
DB schema changed = false
API contract changed = false
status enum changed = false
business flow changed = false
Production touched = false
```

The TEST verification used the real PostgreSQL runtime. There were no genuine trips for the current day, so the live runtime proof covered the PostgreSQL query and empty-state path. Non-empty task cards, attention cards, organization isolation, timezone boundaries, and detail-link behavior remain covered by the automated test suite. No synthetic or fake business order was created for runtime verification.

## TEST runtime

`http://43.156.151.62:8080` is the selected TEST-only runtime.

Verified current state:

- Ubuntu 24.04, PHP 8.4, Laravel 13;
- PostgreSQL 16.14 with `btree_gist` 1.7;
- migrations: 19 ran / 0 pending;
- `/up`: HTTP 200;
- Nginx, PHP-FPM, PostgreSQL, and Scheduler: active;
- deployed source: `6d739fccab4de69f511663e130c1e2308e483afb`;
- main / TEST relation: synchronized;
- authenticated Operator access through the approved existing Cao credential path: PASS; no secret is recorded;
- Today Operations `/operator/today`: PASS on real PostgreSQL;
- desktop and 390px mobile browser checks: PASS;
- business table counts remained unchanged across Today deployment verification;
- existing Docker/public `:80`: untouched;
- Production: untouched.

## Completed synthetic proof

DR04 implementation and synthetic proof remain complete.

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

## Real Pilot configuration

```text
REAL_PILOT = AUTHORIZED
TEST_ONLY = true
REAL_OPERATOR_USE = AUTHORIZED
REAL_PILOT_CONFIGURATION = AUTHORIZED
PLAN_C_CONFIGURATION = READY
PLAN_C_PROVISIONING = COMPLETE
AUTHENTICATED_OPERATOR_ACCESS = PASS_APPROVED_EXISTING_CAO_CREDENTIAL_PATH
HISTORICAL_PLAN_C_MIGRATION = NO
FIRST_REAL_PLAN_C_ORDER = WAITING_FOR_NEXT_GENUINE_ORDER
```

Plan C configuration remains:

- Organization: `Ayany Boat Operations`, timezone `Asia/Bangkok`;
- Boat: `Plan C`, buffer `30 / 30` minutes;
- HOLD TTL: `30` minutes;
- Operator access: approved existing Cao credential path, secret not recorded;
- Slots: `09:00-13:00` 4h AM, `14:00-18:00` 4h PM, `12:00-18:00` 6h, `10:00-18:00` 8h;
- compatibility remains empty, so overlapping use of the same Boat remains fail-closed.

## Completed Calendar UX history

CAL-UX-001 / PR #19:

```text
classification = OBSERVED_OPERATOR_CALENDAR_USABILITY_PAIN
implementation = fe05d92a9534b8e2dac2f4b6af4c6161ec4c4afa
merge commit = 77db16f16617ddcbb09ebf66d83a65a0c97695e5
state = MERGED / CLOSED / TEST DEPLOYED
integration = COMPLETE
schema changed = false
Production touched = false
```

CAL-UX-002 / Issue #23 / PR #24:

```text
classification = OBSERVED_OPERATIONAL_PAIN
historical authoring base = c176b91530019f47145947e63fe5929880d2ff37
merge commit = 44cdb261ce9ec981e948decfceda916c8eca2984
state = MERGED / CLOSED / TEST DEPLOYED
scope = CHINESE-FIRST + QUIET AVAILABLE / EXCEPTION-FIRST PRESENTATION
schema changed = false
Production touched = false
```

CAL-UX-003 / Issue #26 / PR #25:

```text
classification = OBSERVED_OPERATIONAL_PAIN
source = OWNER_REAL_TEST_USE
merge commit = 2f59fb67ab8eea830ef6f8860ed0ee8a2acd9aa7
state = MERGED / CLOSED / TEST DEPLOYED
scope = DURATION-FIRST INQUIRY ENTRY
schema changed = false
Production touched = false
```

All three Calendar UX items are historical completed engineering items. No CAL-UX-004 exists.

## Remaining gate prerequisite

```text
PLAN_C_REAL_CONFIG = READY
TEST_DEPLOYED_SOURCE = 6d739fccab4de69f511663e130c1e2308e483afb
MAIN_TEST_RELATION = SYNCHRONIZED
TODAY_OPERATIONS_V1 = COMPLETE / MERGED / TEST DEPLOYED / VERIFIED
FIRST_REAL_PLAN_C_ORDER = WAITING_FOR_NEXT_GENUINE_ORDER
NO_ACTIVE_ENGINEERING_BLOCKER = true
ENGINEERING = STOP
```

The next operational trigger is Owner real-use feedback or the next genuine Plan C order. Do not create a synthetic or invented “real” order.

## Development rule

```text
NO_NEW_FEATURE_DEVELOPMENT
UNLESS:
  - REAL_PILOT_BLOCKER
  - OBSERVED_OPERATIONAL_PAIN
  - UNIVERSAL_CORE_SAFETY_DEFECT
```

Admin UI, setup wizard, capacity/seat inventory, Product engine, CRM, Finance expansion, reporting, maintenance, historical migration, ChannelHub, OTA, second-company onboarding, and SaaS administration remain deferred unless a genuine operational need reopens them.

## Parallel item

DR16 remains:

```text
status = PARALLEL_BEFORE_CUTOVER
current_real_pilot_blocker = false
main.protected = false
repository rulesets = 0
mutation_authorized = false
```

## Explicit boundaries

```text
PRODUCTION_DEPLOYMENT = false
CUTOVER = false
AUTHORITY_SWITCH = false
CAL_UX_001_TEST_DEPLOYMENT = VERIFIED
CAL_UX_002_TEST_DEPLOYMENT = VERIFIED
CAL_UX_003_TEST_DEPLOYMENT = VERIFIED
TODAY_OPERATIONS_V1_TEST_DEPLOYMENT = VERIFIED
LIVE_MAIN_VS_TEST_TRUTH = SYNCHRONIZED
TAG = false
RELEASE = false
```

This governance reconciliation changes no runtime behavior. It records the already verified Today Operations merge/deployment/runtime facts and preserves the earlier Real Pilot and CAL-UX history. It performs no deployment, provisioning, business-data mutation, schema change, Production change, cutover, tag, or release.
