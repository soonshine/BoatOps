# BoatOps Current Gate

Updated: 2026-08-10 11:44 Asia/Bangkok

## Current decision

`CORE_SAFETY / PR12_MERGE_HOLD / TWO_CORE_INVENTORY_P0_OPEN`

`NO_BUSINESS_CODE_CHANGE / NO_DEPLOYMENT / NO_REAL_DATA / NO_CUTOVER / NOT_RELEASED`

The exact machine state is `CURRENT_STATE.yaml`. Review identities and causal evidence are in `REVIEW_QUEUE.md`; they are not repeated here.

This Gate authorizes neither an implementation nor a merge. A passing test, earlier review, or Draft PR cannot override an open operational-truth invariant.

## CODE / MERGE acceptance

### INV-P0-001 — inventory authority through occupied_end

Required outcome:

- Trip/Booking completion does not release the occupied interval before `occupied_end`;
- overlapping final HOLD, Confirm, Amend, and BLOCK decisions remain rejected before `occupied_end`;
- availability/calendar projections remain consistent with command-side authority;
- SQLite and PostgreSQL concurrency regressions prove the boundary.

### INV-P0-002 — compatibility after completion

Required outcome:

- a completed Booking continues to affect required same-organization, same-Boat, same-service-date slot compatibility;
- incompatible final HOLD, Confirm, and Amend decisions remain rejected;
- Operator Web, API/jobs, and calendar expose the same result.

### Shared acceptance

Any later Owner-authorized repair must:

1. change only what is necessary to close the two P0s;
2. keep Web/API/jobs on shared Application Actions;
3. preserve organization isolation;
4. preserve correct audit, idempotency, inventory revision, and outbox evidence;
5. pass focused and full PHPUnit, contracts, build, migration round-trip, dependency, formatting, and whitespace checks;
6. pass exact-head PostgreSQL concurrency CI;
7. receive independent cross-invariant review;
8. stop for separate Owner merge authorization.

No schema or implementation technique is prescribed here. Every rebase or repair commit creates a new candidate head; earlier head review and CI become history, not acceptance for the new head.

## Gate status

- **CODE / MERGE:** `BLOCKED`; business-code change, implementation branch/commit/PR, and merge are not authorized.
- **DEPLOYMENT:** `NOT_OPEN`; deployment and production enablement are not authorized.
- **REAL DATA / CUTOVER:** `NOT_OPEN`; real data, migration, reconciliation, cutover, and authority switch are not authorized.
- **RELEASE:** `NOT_OPEN`; Tag and GitHub Release are not authorized.

`merge != deploy != cutover != release`

## Allowed now

- read-only code, CI, deployment, and business-input discovery;
- Owner decision on whether to authorize the exact two-P0 repair.

## Forbidden now

- merge PR #12;
- modify business code without a new bounded authorization;
- start WP4/WP5/WP6 or another feature package;
- expand Finance, Stock, CRM, reporting, ChannelHub, OTA, Public API, or Admin UI;
- deploy or enable production;
- read, import, migrate, or cut over real data;
- create a Tag or GitHub Release.

## Next Owner decision

`OWNER_AUTHORIZE_BOUNDED_CORE_INVARIANT_REPAIR`

Only after an authorized repaired candidate passes this Gate and receives separate merge authorization may the Owner open:

`REAL_OPERATIONS_DEPLOYMENT_READINESS`
