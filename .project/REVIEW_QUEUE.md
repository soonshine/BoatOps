# BoatOps Review Queue

Last updated: 2026-08-09 18:20 Asia/Bangkok

Current decision: `WP1_COMPLETE_MERGED / WP2_AUTHORIZED / WP3_WAIT / NO_DEPLOYMENT`

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
| Pilot scope contract | `docs/product/REAL_OPERATIONS_PILOT_MVP_SCOPE_FREEZE.md` | FROZEN |

## WP1 closure

### COMPLETE — Minimal Operational Booking Dossier

PR `#8` was reviewed against exact head `973e0456bf3c8672ae4ba03c61ac0a1c88cfd416`.

Primary review decision:

`WP1_PRIMARY_REVIEW_PASS`

No P0/P1 blocker was found.

Owner then explicitly authorized the WP1 merge. The PR was rebase-merged, producing canonical main:

`1114307d358e67d91ebcf742a26e9d7469209e67`

Post-merge main CI Run `31310579582` passed both `Quality and contracts` and `PostgreSQL concurrency`.

Accepted boundaries:

- WP1 dossier is organization-scoped and editable through authorized Operator context;
- raw contact/location/service-note PII is not copied into generic audit JSON;
- Inventory/Allocation/HOLD/Booking/Trip lifecycle authority remains unchanged;
- no rate snapshot was created from informational selling amount/currency;
- no WP2/WP3 functionality was included;
- no deployment or real data was used.

Non-blocking backlog:

1. Operator-facing selling amount currently uses minor units. Revisit only from Pilot usage or a later bounded decision; do not expand Finance inside WP2.

## Active implementation queue

### ACTIVE — WP2 Minimal Operator Booking Workbench

Status: `AUTHORIZED_TO_START`

Required minimum:

- organization-scoped booking list;
- booking detail;
- today / upcoming / all views or equivalent filters;
- date filter;
- status filter;
- reference/customer search;
- pagination;
- display of WP1 dossier, vessel/service timing, booking state and Trip state;
- reuse existing Amend and Cancel actions from booking context.

Required tests:

- booking list pagination;
- today/upcoming/all behavior;
- date/status/reference/customer filtering;
- organization isolation and permission enforcement;
- booking detail cross-organization rejection;
- WP1 dossier displayed from the existing Inquiry source, not duplicated into a second customer model;
- existing HOLD/Confirm/Amend/Cancel behavior remains authoritative;
- existing contracts and PostgreSQL concurrency gates remain green;
- fictional/synthetic fixtures only.

Explicit WP2 exclusions:

- WP3 Trip Desk/refactor and Trip safety repair;
- new Trip state;
- Finance/payment/refund/profit expansion;
- ChannelHub/OTA/WordPress;
- CRM/manifest/maintenance/documents;
- historical migration;
- real data or deployment.

### WAITING — WP3 Shared Trip Actions + Minimal Operator Trip Desk + Safety Repair

Status: `WAIT_FOR_WP2_REVIEW`

Frozen correction remains:

- no `PREPARED` Trip state;
- preparation remains readiness attached to `PLANNED`;
- successful amendment must invalidate stored readiness;
- departure must require re-prepare;
- actual Trip timestamps require safe ordering validation.

## Existing capability that must be reused

- Availability / occupied intervals;
- HOLD / release / expiry;
- Confirm / Amend / Cancel;
- BLOCK / release;
- Schedule / slot catalog / compatibility / calendar projection;
- Operator auth/membership and Inquiry workflow;
- WP1 operational dossier;
- existing Trip schema/core execution behavior;
- PostgreSQL concurrency protections;
- idempotency / audit / outbox foundations.

## Preserved non-Pilot findings

### Product-code P2

1. Audit rows lack an explicit request/idempotency correlation field.
2. Coarse organization-level write locking may limit same-organization throughput.
3. Existing inquiry/block/audit MVP surfaces may need broader pagination/UX work outside the frozen booking workbench.
4. WP1 selling amount minor-unit input is technically correct but may need operator-friendly UX after Pilot feedback.

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
- current_authorized_slice=WP2
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

Next task: `HERMES_IMPLEMENT_PILOT_MVP_WP2`

`D1_COMPLETE / WP1_COMPLETE_MERGED / WP2_ACTIVE / WP3_WAIT / NO_REAL_DATA / NOT_RELEASED / NO_FUTURE_MERGE_AUTHORIZATION`
