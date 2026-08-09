# BoatOps Review Queue

Last updated: 2026-08-09 17:28 Asia/Bangkok

Current decision: `PILOT_MVP_SCOPE_FROZEN / IMPLEMENTATION_NOT_AUTHORIZED`

## Frozen identities

| Identity | Commit / run / artifact | Status |
| --- | --- | --- |
| G1 reviewed code | `20978a169bbd52278b3bc4ab36e901a55c7e0b00` | COMPLETE / FROZEN |
| D1 product source | `f9503b598b174b7a6891fcde0d984514a3cd0fcd` | COMPLETE / DEPLOYED FICTIONAL DEMO |
| D1 source CI | Run `31294685662` | SUCCESS |
| D1 release | `D1_G1_20260809T045741Z` | COMPLETE / FICTIONAL DEMO |
| D1 SQLite | `62299484458b8e6f63ca7f457ae713d6d3812e31b654ada8847a6d302596b08f` | VERIFIED |
| D0.1 source | `3826cb2c29aea4d2b92a90e04c14f8c218fbf45c` | FROZEN |
| Pilot-roadmap canonical main | `185ebaaac7c5d9f2435eea9faff2f6beeb6f78fe` | SUCCESS / BUSINESS CODE UNCHANGED |
| Pilot scope contract | `docs/product/REAL_OPERATIONS_PILOT_MVP_SCOPE_FREEZE.md` | FROZEN |

## Readiness audit closure

The read-only readiness audit is complete.

Independent Codex decision:

`PILOT_MVP_SCOPE_CAN_BE_FROZEN`

Primary review:

`APPROVE_WITH_CORRECTION`

Verified P0 gaps:

1. Minimal Operational Booking Dossier.
2. Minimal Operator Booking Workbench.
3. Shared Trip Actions + Minimal Operator Trip Desk + readiness/timestamp safety repair.

Reviewer correction:

- Do **not** add a `PREPARED` Trip state for this MVP.
- Existing contract keeps preparation attached to `PLANNED`.
- Successful amendment must invalidate stored crew/checklist readiness atomically.
- Departure after amendment must require re-prepare.

## Frozen MVP scope

### WP1 — Booking Dossier

Minimum structured customer/contact, party size, pickup/meeting, optional service location, source, service/internal notes, and optional selling amount/currency.

### WP2 — Booking Workbench

Organization-scoped, paginated booking list/detail with today/upcoming/all, date/status/reference/customer filtering, lifecycle display, and reuse of existing Amend/Cancel actions.

### WP3 — Trip Desk

Shared `Application/Trips` actions, Operator Today's Trips/detail, crew/checklist preparation, Depart/Return/Complete, amendment-readiness invalidation, and actual-time safety validation.

Canonical details and acceptance tests:

`docs/product/REAL_OPERATIONS_PILOT_MVP_SCOPE_FREEZE.md`

## Existing capability that must be reused

- Availability / occupied intervals;
- HOLD / release / expiry;
- Confirm / Amend / Cancel;
- BLOCK / release;
- Schedule / slot catalog / compatibility / calendar projection;
- Operator auth/membership and Inquiry workflow;
- existing Trip schema/core execution behavior;
- PostgreSQL concurrency protections;
- idempotency / audit / outbox foundations.

## Preserved non-Pilot findings

### Product-code P2

1. Audit rows lack an explicit request/idempotency correlation field.
2. Coarse organization-level write locking may limit same-organization throughput.
3. Existing inquiry/block/audit MVP surfaces may need broader pagination/UX work outside the frozen booking workbench.

### GitHub governance

1. `main` is unprotected; required checks are not enforced by branch protection.
2. Repository rulesets = `0`.
3. GitHub Environments / Deployments remain unused for the existing external Demo deployment.
4. Superseded historical branches remain repository-hygiene work.
5. No formal LICENSE, Tag or GitHub Release exists.

## Deployment remains separate

Real Pilot deployment is not authorized by this scope freeze.

Minimum later deployment concerns include PostgreSQL, actual organization/vessels/rules, real Operators, HOLD expiry scheduler, backup/restore, health/logging, PII protection, physical Demo isolation and explicit real-data/cutover authorization.

Automated historical migration is not required for the first Pilot unless later evidence proves otherwise.

## Current authorization boundary

- readiness audit = complete
- MVP scope = frozen
- business_code_change_authorized=false
- merge_authorized=false
- deployment_authorized=false
- tag_authorized=false
- release_authorized=false
- production_enablement_authorized=false
- production_data_authorized=false
- google_sheet_migration_authorized=false
- channelhub_authorized=false
- ota_authorized=false

Next task: `OWNER_AUTHORIZE_PILOT_MVP_IMPLEMENTATION`

`D1_COMPLETE / PILOT_MVP_SCOPE_FROZEN / NO_REAL_DATA / NOT_RELEASED / IMPLEMENTATION_NOT_AUTHORIZED`
