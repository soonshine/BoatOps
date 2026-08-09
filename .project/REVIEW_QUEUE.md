# BoatOps Review Queue

Last updated: 2026-08-09 17:40 Asia/Bangkok

Current decision: `PILOT_MVP_IMPLEMENTATION_AUTHORIZED / WP1_ONLY / NO_MERGE / NO_DEPLOYMENT`

## Frozen identities

| Identity | Commit / run / artifact | Status |
| --- | --- | --- |
| G1 reviewed code | `20978a169bbd52278b3bc4ab36e901a55c7e0b00` | COMPLETE / FROZEN |
| D1 product source | `f9503b598b174b7a6891fcde0d984514a3cd0fcd` | COMPLETE / DEPLOYED FICTIONAL DEMO |
| D1 source CI | Run `31294685662` | SUCCESS |
| D1 release | `D1_G1_20260809T045741Z` | COMPLETE / FICTIONAL DEMO |
| D1 SQLite | `62299484458b8e6f63ca7f457ae713d6d3812e31b654ada8847a6d302596b08f` | VERIFIED |
| Pilot scope-freeze main | `ae62d26f418ab841a67497387d03a904e33e9064` | SUCCESS / IMPLEMENTATION BASELINE |
| Pilot scope contract | `docs/product/REAL_OPERATIONS_PILOT_MVP_SCOPE_FREEZE.md` | FROZEN |

## Readiness audit closure

Independent Codex decision: `PILOT_MVP_SCOPE_CAN_BE_FROZEN`

Primary review: `APPROVE_WITH_CORRECTION`

Frozen correction:

- do not add a `PREPARED` Trip state;
- preparation remains readiness attached to `PLANNED`;
- successful amendment must invalidate stored readiness;
- later departure must require re-prepare;
- actual trip timestamps require safe ordering validation in WP3.

## Owner authorization

The Owner explicitly authorized implementation of the frozen Real Operations Pilot MVP.

Execution is staged to minimize scope and review risk:

1. WP1 implementation now;
2. primary review before WP2;
3. WP2 implementation;
4. primary review before WP3;
5. WP3 implementation;
6. primary review before any merge/deployment decision.

This authorization permits implementation branch, commits and PR creation inside the frozen scope. It does not authorize merge or deployment.

## Active implementation queue

### ACTIVE — WP1 Minimal Operational Booking Dossier

Status: `AUTHORIZED_TO_START`

Required minimum:

- structured customer/contact name;
- contact method/value;
- party size;
- pickup/meeting point;
- optional dropoff/service location;
- sales source and optional agent/partner reference;
- customer/service notes separated from internal operations notes;
- optional selling amount/currency using existing rate-snapshot foundations where appropriate;
- organization scoping, validation and PII-safe audit behavior.

Required tests:

- validation boundaries;
- organization isolation / cross-org denial;
- PII audit redaction/non-disclosure;
- existing Inquiry/HOLD/Confirm/Amend/Cancel regression coverage;
- migration round-trip;
- existing contracts remain valid;
- PostgreSQL CI remains green;
- fictional/synthetic fixtures only.

Explicit WP1 exclusions:

- WP2 Booking Workbench;
- WP3 Trip UI/refactor;
- new Trip state;
- Finance expansion;
- ChannelHub/OTA/WordPress/payment;
- CRM/manifest/maintenance/documents;
- historical migration;
- real data or deployment.

### WAITING — WP2 Minimal Operator Booking Workbench

Status: `WAIT_FOR_WP1_REVIEW`

### WAITING — WP3 Shared Trip Actions + Minimal Operator Trip Desk + Safety Repair

Status: `WAIT_FOR_WP2_REVIEW`

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
- business_code_change_authorized=true
- implementation_branch_authorized=true
- implementation_commit_authorized=true
- implementation_pr_authorized=true
- current_authorized_slice=WP1
- merge_authorized=false
- deployment_authorized=false
- tag_authorized=false
- release_authorized=false
- production_enablement_authorized=false
- production_data_authorized=false
- google_sheet_migration_authorized=false
- channelhub_authorized=false
- ota_authorized=false

Next task: `HERMES_IMPLEMENT_PILOT_MVP_WP1`

`D1_COMPLETE / PILOT_MVP_SCOPE_FROZEN / IMPLEMENTATION_AUTHORIZED / WP1_ACTIVE / NO_REAL_DATA / NOT_RELEASED / NO_MERGE`
