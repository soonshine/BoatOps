# BoatOps Current Gate

Updated: 2026-08-13 12:29 Asia/Bangkok

## Current decision

```text
REAL_PILOT_EXECUTION
CORE_SAFETY_COMPLETE
TEST_RUNTIME_READY
SYNTHETIC_VERTICAL_SLICE_COMPLETE
REAL_PILOT_AUTHORIZED
PLAN_C_CONFIGURATION_READY
PR_18_MERGED
CAL_UX_001_INTEGRATION_COMPLETE
NO_NEW_FEATURE_DEVELOPMENT
```

BoatOps has left the active Deployment Readiness / governance-planning phase. The primary job remains the shortest TEST-only path to real Plan C use. No global new-feature package is open; CAL-UX-001 is a narrow already-authorized exception for observed operational pain.

The exact machine-readable state is in `CURRENT_STATE.yaml`; the small operational queue is in `REVIEW_QUEUE.md`.

`merge != deploy != cutover != release`

## Exact GitHub identity

```text
canonical main:
  verified authoring baseline: 77db16f16617ddcbb09ebf66d83a65a0c97695e5
  live head: resolve refs/heads/main from GitHub at each later gate
  PR #18 included: YES
  PR #19 included: YES
  Real Pilot candidate ancestry included: YES
  CAL-UX-001 implementation ancestry included: YES

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
```

`77db16f16617ddcbb09ebf66d83a65a0c97695e5` is the verified canonical-main baseline at authoring time, not the unknown future SHA produced by merging this governance receipt. `LIVE_BRANCH_REF_IS_EXTERNAL_STATE` remains in force. The immutable Real Pilot and CAL-UX-001 implementation commits are both included in `main` ancestry.

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

This makes the TEST runtime `READY`. It does not mean the exact Real Pilot candidate is already deployed.

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
deployment = NOT_AUTHORIZED / NOT_DEPLOYED
tag = NOT_AUTHORIZED
release = NOT_AUTHORIZED
```

CAL-UX-001 was an authorized narrow exception under `OBSERVED_OPERATIONAL_PAIN`; its integration is complete and it does not reopen feature development globally. Merge authorization has been consumed and does not authorize Deployment, Tag, Release, Cutover, or an authority switch.

## Current blocker

```text
PLAN_C_REAL_CONFIG = READY
TEST_DEPLOYED_REAL_PILOT_HEAD = NOT_YET
PLAN_C_PROVISIONING = PENDING
REAL_OPERATOR_SECRET = NOT_CONFIGURED
CAO_LOGIN_SMOKE = PENDING
```

This is an execution-secret blocker, not a code blocker. Do not add code to bypass secret injection.

## Allowed now

Only the following Real Pilot execution path is active:

```text
deploy exact Real Pilot candidate to TEST
-> provision Plan C
-> Cao login/calendar smoke
-> next real Plan C order
-> record observed operational pain
```

The next real order will use:

```text
Inquiry -> HOLD -> Confirm -> Prepare -> Depart -> Return -> Complete -> Audit
```

Historical Plan C orders will not be migrated. Do not create a synthetic or invented “real” order.

CAL-UX-001 integration is complete and does not replace or alter the Real Pilot operational path:

```text
PR #19 = MERGED / CLOSED
implementation fe05d92a9534b8e2dac2f4b6af4c6161ec4c4afa = INCLUDED IN MAIN ANCESTRY
merge commit = 77db16f16617ddcbb09ebf66d83a65a0c97695e5
deployment = NOT_AUTHORIZED / NOT_DEPLOYED
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

Routine progress does not require a governance-only PR. State/document updates normally travel with the relevant implementation PR.

## Parallel items

DR16 remains `PARALLEL_BEFORE_CUTOVER / NOT_CURRENT_REAL_PILOT_BLOCKER`.

```text
main.protected = false
repository rulesets = 0
DR16 mutation authorized = false
```

This task does not change GitHub settings.

CAL-UX-001 is `CODE_REVIEW_ACCEPTED / OWNER_MERGE_AUTHORIZATION_GRANTED_AND_CONSUMED / PR19_MERGED_CLOSED / INTEGRATION_COMPLETE`. The branch `codex/cal-ux-001` is historical and no longer active implementation authority.

## Explicit boundaries

```text
PRODUCTION_DEPLOYMENT = false
CUTOVER = false
AUTHORITY_SWITCH = false
PR_19 = MERGED_CLOSED
CAL_UX_001_DEPLOYMENT = false
TAG = false
RELEASE = false
```

This governance-only synchronization changes no runtime behavior. Production, Docker, public `:80`, Plan C provisioning, real data, and the CAL-UX-001 implementation branch remain untouched.

## Closed history

Core Safety and the prior Deployment Readiness planning work remain accepted history, not active queue items. PR #12, PR #15, PR #16, PR #18, and PR #19 are merged/closed. PR #19 merged the accepted CAL-UX-001 implementation as `77db16f16617ddcbb09ebf66d83a65a0c97695e5`. Their evidence remains in Git history and the branch ledger.
