# BoatOps Review Queue

Last updated: 2026-08-10 08:01 Asia/Bangkok

Current decision: `WP1_COMPLETE_MERGED / WP2_COMPLETE_MERGED / WP3_AUTHORIZED / NO_DEPLOYMENT`

## Canonical identities

| Identity | Commit / run / artifact | Status |
| --- | --- | --- |
| G1 reviewed code | `20978a169bbd52278b3bc4ab36e901a55c7e0b00` | COMPLETE / FROZEN |
| D1 product source | `f9503b598b174b7a6891fcde0d984514a3cd0fcd` | COMPLETE / DEPLOYED FICTIONAL DEMO |
| Pilot scope-freeze main | `ae62d26f418ab841a67497387d03a904e33e9064` | FROZEN |
| WP1 reviewed head | `973e0456bf3c8672ae4ba03c61ac0a1c88cfd416` | PRIMARY REVIEW PASS |
| WP1 exact-head CI | Run `31310148095` | SUCCESS |
| WP1 merged main | `1114307d358e67d91ebcf742a26e9d7469209e67` | COMPLETE / MERGED |
| WP1 post-merge main CI | Run `31310579582` | SUCCESS |
| WP2 reviewed head | `b340e7c84480c6bcc92ae62829cad0f7f0661fec` | PRIMARY REVIEW PASS |
| WP2 exact-head CI | Run `31317044622` | SUCCESS |
| WP2 merged main | `763d22bfc4ddaf0a84df1188d50f6d40b2fa72fc` | COMPLETE / MERGED |
| WP2 post-merge main CI | Run `31346016491` | SUCCESS |
| Pilot scope contract | `docs/product/REAL_OPERATIONS_PILOT_MVP_SCOPE_FREEZE.md` | FROZEN |

## WP1 closure

### COMPLETE — Minimal Operational Booking Dossier

PR `#8` was reviewed and merged. No P0/P1 blocker was found. The canonical merged main is `1114307d358e67d91ebcf742a26e9d7469209e67`, and post-merge CI Run `31310579582` passed.

Accepted boundaries remain:

- organization-scoped editable operational dossier;
- PII-safe generic audit behavior;
- no change to authoritative Inventory/Booking lifecycle semantics;
- no fake rate snapshot or Finance expansion;
- no deployment or real data.

Non-blocking backlog:

1. Operator-facing selling amount currently uses minor units. Revisit only from Pilot usage or a later bounded decision.

## WP2 closure

### COMPLETE — Minimal Operator Booking Workbench

PR `#10` was reviewed against exact head `b340e7c84480c6bcc92ae62829cad0f7f0661fec`.

Primary review decision:

`WP2_PRIMARY_REVIEW_PASS`

No P0/P1 blocker was found.

Owner explicitly authorized the WP2 merge. The PR was rebase-merged, producing canonical main:

`763d22bfc4ddaf0a84df1188d50f6d40b2fa72fc`

Post-merge main CI Run `31346016491` passed both `Quality and contracts` and `PostgreSQL concurrency`.

Accepted boundaries:

- organization-scoped Booking list/detail;
- organization-local Today / Upcoming / explicit-date semantics;
- bounded status/reference/customer filters and pagination;
- WP1 dossier displayed from the existing Inquiry source, not duplicated;
- direct/API-style Bookings without an Inquiry remain visible and manageable;
- Booking-context Amend/Cancel continue to reuse authoritative Application Booking actions;
- reads are non-mutating;
- no migration, new Booking lifecycle, Trip mutation, Finance expansion, deployment or real data.

Non-blocking backlog:

1. Booking detail can still render Amend/Cancel controls after the linked Trip progresses beyond `PLANNED`; authoritative actions correctly reject invalid transitions. WP3 may make the UI lifecycle-aware without becoming lifecycle authority.

## Active implementation queue

### ACTIVE — WP3 Shared Trip Actions + Minimal Operator Trip Desk + Safety Repair

Status: `AUTHORIZED_TO_START`

Required implementation:

1. Extract/reuse shared Application Trip actions for Prepare / Depart / Return / Complete.
2. Route existing Operations API Trip adapters through those shared actions so API and Operator UI share one mutation engine.
3. Add a minimal organization-scoped Operator Trip Desk / Today's Trips surface.
4. Display the Booking/Trip context needed for execution, including crew/checklist readiness.
5. Allow authorized Operator execution of Prepare / Depart / Return / Complete.
6. Preserve Trip status flow `PLANNED -> DEPARTED -> RETURNED -> COMPLETED`.
7. Do not add `PREPARED`.
8. On successful Trip-plan amendment, invalidate stale crew/checklist readiness atomically and require re-prepare before Depart.
9. Enforce actual timestamp integrity: no future Depart, no Return before Depart, no future Return, no Complete before Return.
10. Preserve idempotency/audit evidence and use synthetic/fictional fixtures only.

Required tests:

- existing Operations API Trip behavior characterized and preserved;
- Operator organization/permission isolation;
- Today's Trips uses organization timezone semantics where relevant;
- Prepare stores crew/checklist readiness while Trip remains `PLANNED`;
- Depart requires current readiness;
- successful Amend invalidates stale readiness and blocks departure until re-prepare;
- failed Amend does not erase valid readiness;
- future Depart rejected;
- Return before Depart rejected;
- future Return rejected;
- Complete before Return rejected;
- idempotency replay/conflict preserved for shared actions;
- audit evidence preserved;
- WP1/WP2 regressions and PostgreSQL concurrency gates stay green.

Explicit WP3 exclusions:

- new `PREPARED` Trip state;
- second Trip state machine;
- Finance/payment/refund/profit expansion;
- ChannelHub/OTA/WordPress;
- CRM/manifest expansion/maintenance/documents;
- historical migration;
- real data or deployment.

## Existing capability that must be reused

- Availability / occupied intervals;
- HOLD / release / expiry;
- Confirm / Amend / Cancel;
- BLOCK / release;
- Schedule / slot catalog / compatibility / calendar projection;
- Operator auth/membership and Inquiry workflow;
- WP1 operational dossier;
- WP2 Booking Workbench;
- existing Trip tables/core execution behavior;
- PostgreSQL concurrency protections;
- idempotency / audit / outbox foundations.

## Preserved non-Pilot findings

### Product-code P2

1. Audit rows lack an explicit request/idempotency correlation field.
2. Coarse organization-level write locking may limit same-organization throughput.
3. Existing inquiry/block/audit MVP surfaces may need broader pagination/UX work outside the frozen Booking Workbench.
4. WP1 selling amount minor-unit input may need operator-friendly UX after Pilot feedback.

### GitHub governance

1. `main` remains unprotected; required checks are not enforced by branch protection.
2. Repository rulesets = `0`.
3. GitHub Environments / Deployments remain unused for the existing external Demo deployment.
4. Superseded historical branches remain repository-hygiene work.
5. No formal LICENSE, Tag or GitHub Release exists.

## Deployment remains separate

Real Pilot deployment is not authorized.

Minimum later deployment concerns include PostgreSQL, actual organization/vessels/rules, real Operators, HOLD expiry scheduler, backup/restore, health/logging, PII protection, physical Demo isolation and explicit real-data/cutover authorization.

Automated historical migration is not required for the first Pilot unless later evidence proves otherwise.

## Current authorization boundary

- readiness audit = complete
- MVP scope = frozen
- WP1 = complete_merged
- WP2 = complete_merged
- current_authorized_slice=WP3
- business_code_change_authorized=true
- implementation_branch_authorized=true
- implementation_commit_authorized=true
- implementation_pr_authorized=true
- future_merge_authorized=false
- deployment_authorized=false
- tag_authorized=false
- release_authorized=false
- production_enablement_authorized=false
- production_data_authorized=false
- google_sheet_migration_authorized=false
- channelhub_authorized=false
- ota_authorized=false

Next task: `HERMES_IMPLEMENT_PILOT_MVP_WP3`

`D1_COMPLETE / WP1_COMPLETE_MERGED / WP2_COMPLETE_MERGED / WP3_ACTIVE / NO_REAL_DATA / NOT_RELEASED / NO_FUTURE_MERGE_AUTHORIZATION`
