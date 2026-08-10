# BoatOps Current Gate

Updated: 2026-08-10 14:21 Asia/Bangkok

## Current decision

This governance reconciliation is a candidate until merged to canonical `main`.

While this governance PR is Draft or unmerged:

`GOVERNANCE_RECONCILIATION_CANDIDATE / NO_ACTION_WHILE_UNMERGED`

Only after this exact governance change is merged to canonical `main`:

`CORE_SAFETY / BOUNDED_REPAIR_AUTHORIZED / PR12_MERGE_BLOCKED / TWO_REQUIRED_CORE_INVARIANTS`

The authorization scope is exactly:

`BOUNDED_CORE_SAFETY_REPAIR_ONLY`

The exact machine state is `CURRENT_STATE.yaml`. Review identities and causal evidence are in `REVIEW_QUEUE.md`.

## Required Core Safety repair

### INV-P0-001 - physical inventory authority through occupied_end

The repair must prove:

1. physical inventory remains authoritative through `occupied_end`;
2. at least one non-zero buffer case is covered;
3. an overlapping HOLD before `occupied_end` remains rejected;
4. an overlapping BLOCK before `occupied_end` remains rejected;
5. Confirm and Amend cannot bypass the same authority;
6. Calendar and availability do not advertise materially unsafe availability;
7. behavior at and after the `occupied_end` boundary is explicit;
8. inventory revision, outbox, and idempotency remain correct and exactly once;
9. a PostgreSQL regression proves the real constraint path.

No new Trip or Allocation state is required unless the invariant is otherwise impossible to preserve.

### INV-P0-002 - completed Booking same-service-date compatibility

The repair must prove:

1. a completed Booking retains the relevant same-date compatibility effect;
2. the effect is limited to the same organization, Boat, service date, and relevant Slot identity;
3. it does not become a permanent physical-overlap blocker;
4. HOLD, Confirm, and Amend use the correct result;
5. Calendar exposes the same compatibility result;
6. an incompatible pair remains rejected after the first Booking completes;
7. an explicitly ALLOWed, non-overlapping pair remains sellable.

Physical occupied-interval authority and logical same-service-date compatibility remain separate invariants.

## Allowed adjacent hardening

### INV-P0-004 - Cancel fail-closed cleanup

This cleanup is optional and is the only adjacent hardening allowed in the current repair.

If implemented, it must prove:

1. Cancel accepts `Trip.status = PLANNED`;
2. Cancel rejects `PREPARED`;
3. unknown or non-contract Trip states reject fail closed;
4. rejection causes no partial write;
5. the stale test expecting PREPARED cancellation is replaced;
6. no new Trip state is introduced.

## Primary reconciliation boundaries

- `INV-P0-003`: downgraded to `DEFENSE_IN_DEPTH`; not a current Gate requirement. A tiny reuse of existing validation is acceptable only if it adds no scope while repairing Complete.
- `REALUSE-P1-001`: `DEFER_OBSERVED_PAIN_REQUIRED`; do not add Inquiry selection editing or re-HOLD behavior now.
- `REALUSE-P1-002`: `DEFER_UNTIL_REAL_COMPLIANCE_OR_AUDIT_NEED`; do not expand readiness history or audit PII now.

Codex's raw counter-audit severities are evidence, not current Gate authority after Primary reconciliation.

## CODE / MERGE status after this governance change is merged

- **Business-code change:** `AUTHORIZED`, limited to `BOUNDED_CORE_SAFETY_REPAIR_ONLY`.
- **Implementation branch/commit/PR:** `AUTHORIZED`, limited to the same scope.
- **PR #12 rebase:** `AUTHORIZED` onto the then-current exact canonical `main`.
- **PR #12 merge:** `NOT_AUTHORIZED / BLOCKED`.
- **DEPLOYMENT:** `NOT_OPEN`.
- **PRODUCTION ENABLEMENT:** `NOT_AUTHORIZED`.
- **REAL DATA / MIGRATION / CUTOVER / AUTHORITY SWITCH:** `NOT_OPEN / NOT_AUTHORIZED`.
- **TAG / RELEASE:** `NOT_OPEN / NOT_AUTHORIZED`.

Passing tests, CI, or review never grants merge, deployment, cutover, or release authority.

`merge != deploy != cutover != release`

## Next implementation action after governance merge

`HERMES_REBASE_PR12_AND_IMPLEMENT_BOUNDED_CORE_SAFETY_REPAIR`

Allowed implementation:

1. rebase PR #12 onto the then-current exact `main`;
2. repair `INV-P0-001`;
3. repair `INV-P0-002`;
4. optionally apply only the `INV-P0-004` fail-closed cleanup;
5. add tests directly necessary to prove those outcomes.

Every rebase or repair commit creates a new candidate head. Review and CI from `d841418c24c90c30ceeb203e17150e55cb46d538` remain history and cannot accept the new head.

## Explicitly not required or authorized

- a generalized Complete corruption or integrity framework;
- Inquiry selection editing or re-HOLD redesign;
- richer readiness history or audit framework;
- passenger capacity or Product/Slot mapping;
- provisioning or Admin UI;
- Finance, CRM, Stock, reporting, notifications, maintenance, ChannelHub, OTA, or Public API expansion;
- deployment work or production enablement;
- real data, migration, reconciliation, cutover, or authority switch;
- Tag or GitHub Release;
- WP4, WP5, WP6, or another feature package.

## Required validation for the future repair

The repaired exact head must pass:

- focused and full PHPUnit;
- Pint;
- contracts and event fixtures;
- frontend build;
- SQLite migration round-trip;
- Composer and NPM dependency audits;
- whitespace checks;
- exact-head `Quality and contracts` GitHub CI;
- exact-head `PostgreSQL concurrency` GitHub CI;
- independent cross-invariant review.

It must then stop for:

`OWNER_AUTHORIZE_PR12_MERGE`

Only after a separately authorized merge may the Owner consider opening `REAL_OPERATIONS_DEPLOYMENT_READINESS`.
