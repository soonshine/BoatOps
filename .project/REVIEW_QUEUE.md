# BoatOps Review Queue

Last updated: 2026-08-09 16:17 Asia/Bangkok

Current decision: `D1_COMPLETE / NEXT_PRODUCT_GATE_UNDEFINED`

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

## Current product truth

- BoatOps is a reusable organization-scoped vessel operations product.
- Ayany is not a hard-coded tenant, vessel owner, or required integration.
- The current two-vessel Plan A / Plan B scenario is deployment/reference data, not an Ayany ownership fact.
- G1 Operator MVP is complete in source and was exercised in D1 using fictional isolated data.
- D1 is a fictional Demo deployment/validation, not production.
- Production PostgreSQL is not enabled by D1.
- Real data has not been authorized or migrated.
- ChannelHub, OTA, payment, WordPress inventory integration, and Google Sheet migration remain outside the active gate.

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

## Owner decisions required before real operations

| Item | Current treatment |
| --- | --- |
| Actual operating organization/tenant | NOT FROZEN |
| Vessel ownership / operating-right relationship where needed | DEPLOYMENT DATA / NOT FROZEN |
| Actual schedules and service windows | CONFIGURABLE / NOT FROZEN |
| Buffer values | CONFIGURABLE / NOT FROZEN |
| HOLD TTL, extension, re-HOLD | CONFIGURABLE / NOT FROZEN |
| Slot compatibility / mutual exclusion | CONFIGURABLE / NOT FROZEN |
| Weather policy | CONFIGURABLE / NOT FROZEN |
| Custom-slot policy | CONFIGURABLE / NOT FROZEN |
| Production Operator identities/permissions | OWNER CONFIGURATION REQUIRED |
| Real products/prices/customers/orders | REAL DATA / SEPARATE AUTHORIZATION |
| Production PostgreSQL | SEPARATE DEPLOYMENT AUTHORIZATION |
| Migration/reconciliation/cutover | SEPARATE DATA AUTHORIZATION |

## Next-gate discipline

There is no approved `G2A` definition.

Before any new product gate:

1. state the business outcome;
2. inventory what already exists in `main` so existing Trip, finance, schedule, inventory, or Operator capabilities are not reimplemented;
3. identify the smallest missing application/domain/UI slice;
4. define acceptance tests and explicit exclusions;
5. obtain Owner authorization for business-code work.

## Current authorization boundary

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

Next task: `DEFINE_NEXT_PRODUCT_GATE`

`D1_COMPLETE / FICTIONAL_DEMO_ONLY / NO_REAL_DATA / NOT_TAGGED / NOT_RELEASED / NEXT_GATE_UNDEFINED`
