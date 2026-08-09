# BoatOps Review Queue

Last updated: 2026-08-09 16:52 Asia/Bangkok

Current decision: `D1_COMPLETE / REAL_OPERATIONS_PILOT_MVP_PROPOSED / NOT_AUTHORIZED`

## Frozen identities

| Identity | Commit / run / artifact | Status |
| --- | --- | --- |
| G1 reviewed code | `20978a169bbd52278b3bc4ab36e901a55c7e0b00` | COMPLETE / FROZEN |
| G1 main governance | `f9503b598b174b7a6891fcde0d984514a3cd0fcd` | COMPLETE / D1 SOURCE |
| D1 source CI | Run `31294685662` | SUCCESS |
| D1 release | `D1_G1_20260809T045741Z` | COMPLETE / FICTIONAL DEMO |
| D1 SQLite | `62299484458b8e6f63ca7f457ae713d6d3812e31b654ada8847a6d302596b08f` | VERIFIED |
| D1 rollback script | `0f785385bd57c8165470f436e71009a11e4971b2687a48d1da36e5e2bacad11a` | AUTHORITATIVE |
| D0.1 source | `3826cb2c29aea4d2b92a90e04c14f8c218fbf45c` | FROZEN |
| D0.1 SQLite | `97d7738c866fa5df6062650da25e644258a4e5c60255c6e1ad83e7ea65632ab4` | VERIFIED |
| Canonical governance main before pilot-roadmap sync | `ff5345da4ace8bb9301d172ac68fb437a1ca154c` | SUCCESS / BUSINESS CODE UNCHANGED |

## Current product truth

- BoatOps is a reusable organization-scoped vessel operations product.
- Ayany is not a hard-coded tenant, vessel owner, or required integration.
- The current two-vessel Plan A / Plan B scenario is deployment/reference data, not an Ayany ownership fact.
- G1 Operator MVP is complete in source and was exercised in D1 using fictional isolated data.
- D1 is a fictional Demo deployment/validation, not production.
- Production PostgreSQL is not enabled by D1.
- Real data has not been authorized or migrated.
- ChannelHub, OTA, payment, WordPress inventory integration, and Google Sheet migration remain outside the active gate.

## Product direction now proposed

The Owner has selected an iteration strategy centered on reaching real operational use quickly and improving BoatOps from actual operating feedback rather than attempting feature completeness first.

The next product-gate candidate is:

`REAL_OPERATIONS_PILOT_MVP`

Status:

`PROPOSED / NOT_AUTHORIZED`

Canonical roadmap:

`docs/product/REAL_OPERATIONS_PILOT_MVP.md`

Six-step path:

1. bounded read-only MVP readiness audit;
2. Minimal Operational Booking Dossier;
3. Minimal Operator Trip Desk over existing Trip engine;
4. separate real-operations deployment readiness using PostgreSQL and actual configuration;
5. small controlled cutover;
6. usage-driven iteration.

This direction does not authorize implementation. Exact acceptance criteria must be frozen after the readiness audit.

## Existing capability that must be reused

Current source already contains substantial foundations and the next implementation must not rebuild them without evidence of a missing invariant:

- Inventory availability and occupied intervals;
- HOLD / release / expiry;
- Booking confirm / amend / cancel;
- BLOCK / release;
- Schedule / slot catalog / compatibility / calendar projection;
- Operator auth, calendar, Inquiry/HOLD, Booking workflow, BLOCK and audit;
- Trip crew/checklist and prepare/depart/return/complete backend transitions;
- PostgreSQL concurrency validation;
- finance/stock/cash candidate foundations.

## Likely minimal pilot gaps to verify, not yet accepted as final scope

### Operational Booking Dossier

Verify the smallest structured order/service information needed for a real operator, likely including customer/contact, party size, pickup/meeting point, sales source, service/internal notes, currency and selling amount.

Do not expand this into CRM, payment, settlement, or accounting in the first pilot.

### Operator Trip Desk

Verify the minimal UI needed for today's Trip execution. Existing Trip transaction/business behavior should be extracted/reused as shared Application Actions if still trapped in API controllers; do not build a parallel Trip state machine.

### Real deployment readiness

Treat PostgreSQL, actual organization/vessel configuration, real Operator identities, backup/restore and data authorization as a separate deployment gate. D1 SQLite is not a production baseline.

## Explicit first-pilot exclusions

- ChannelHub;
- OTA;
- WordPress inventory integration;
- payment gateway;
- complete receivables/accounting;
- profit dashboard;
- broad stock/fuel UI expansion;
- complex SaaS admin;
- automated historical migration;
- notification center;
- reporting platform;
- maintenance management;
- second-operator onboarding;
- public semantic-version release.

## Preserved findings

### Product-code P2

1. Audit rows lack an explicit request/idempotency correlation field.
2. Coarse organization-level write locking may limit same-organization throughput.
3. Operator inquiry/block/audit listings remain unpaginated MVP surfaces.

### GitHub-governance findings

1. `main` is currently unprotected; required checks are not enforced by GitHub branch protection.
2. Repository rulesets = `0`.
3. GitHub Environments = `0`; GitHub Deployments = `0` even though an operational Demo exists outside GitHub's Deployment API.
4. The D1 experimental source branch is superseded and must never be used as the D1 deployment baseline.
5. Historical branches remain on the remote and should be cleaned only under explicit branch-cleanup authorization.
6. No formal LICENSE, Tag, or GitHub Release exists.

These governance findings do not invalidate D1, but they should be addressed before production or broader contributor workflows.

## Branch classification

See `.project/BRANCH_LEDGER.md`.

Critical classification:

`codex/boatops-d1-g1-demo-deployment` = `ABANDONED / SUPERSEDED / DO_NOT_MERGE`

The successful D1 deployment used exact source `f9503b598b174b7a6891fcde0d984514a3cd0fcd` with no D1 business-source change.

## Decisions required before implementation

| Item | Current treatment |
| --- | --- |
| MVP readiness audit | NOT YET AUTHORIZED |
| Exact MVP acceptance contract | NOT FROZEN |
| Business-code change | NOT AUTHORIZED |
| Merge | NOT AUTHORIZED |
| Actual operating organization/tenant | NOT FROZEN |
| Actual vessels / operating-right relationship | DEPLOYMENT DATA / NOT FROZEN |
| Actual schedules, buffer and HOLD policy | CONFIGURABLE / NOT FROZEN |
| Production Operator identities/permissions | OWNER CONFIGURATION REQUIRED |
| Real products/prices/customers/orders | REAL DATA / SEPARATE AUTHORIZATION |
| Production PostgreSQL | SEPARATE DEPLOYMENT AUTHORIZATION |
| Migration/reconciliation/cutover | SEPARATE DATA AUTHORIZATION |

## Next action discipline

Before any business-code work:

1. run the bounded MVP readiness audit;
2. compare audit findings to current main source/tests;
3. select the smallest implementation slice;
4. freeze acceptance criteria and explicit exclusions;
5. obtain Owner authorization for business-code work and merge.

Current authorization boundary:

- mvp_readiness_audit_authorized=false
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

Next task: `AUTHORIZE_AND_RUN_MVP_READINESS_AUDIT`

`D1_COMPLETE / FICTIONAL_DEMO_ONLY / REAL_OPERATIONS_PILOT_MVP_PROPOSED / NO_REAL_DATA / NOT_TAGGED / NOT_RELEASED / IMPLEMENTATION_NOT_AUTHORIZED`
