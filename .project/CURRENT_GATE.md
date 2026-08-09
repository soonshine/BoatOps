# BoatOps Current Gate

Updated: 2026-08-09 16:52 Asia/Bangkok

## Current authoritative decision

`D1_G1_FICTIONAL_DEMO_DEPLOYMENT = COMPLETE / APPROVED`

D1 remains frozen as fictional Demo validation only.

- Deployed product source: `f9503b598b174b7a6891fcde0d984514a3cd0fcd`
- D1 source change: `NO`
- Real data: `NONE`
- Production PostgreSQL: `NOT_ENABLED`
- Production inventory master: `NOT_ENABLED`
- Tag: `NONE`
- GitHub Release: `NONE`
- ChannelHub / OTA / payment / WordPress inventory integration: `NOT_STARTED / NOT_AUTHORIZED`

## Next product direction

The next product-gate candidate is now:

`REAL_OPERATIONS_PILOT_MVP`

Status:

`PROPOSED / NOT_AUTHORIZED`

The product objective is to reach the smallest safe version that can be used in actual daily vessel operations, then iterate from real usage.

This is a product-direction decision only. It does not authorize business-code changes, merge, deployment, production data, cutover, Tag, Release, ChannelHub, OTA or payment work.

Canonical roadmap:

`docs/product/REAL_OPERATIONS_PILOT_MVP.md`

## Proposed six-step realization path

1. **MVP Readiness Audit** — read-only audit of current `main`; identify the smallest missing slice and existing capability that must be reused.
2. **Minimal Operational Booking Dossier** — only the order/service information required for daily execution, plus minimal list/detail surfaces.
3. **Minimal Operator Trip Desk** — expose the existing Trip engine through Operator UI; extract/reuse shared Trip Application Actions instead of creating a parallel state machine.
4. **Real Operations Deployment Readiness** — separate gate for PostgreSQL, actual organization/vessel/configuration, real Operator identities, backup/restore and production-data authorization.
5. **Small Real Cutover** — prefer new/future operational records and minimal manual entry over broad historical migration for the first pilot.
6. **Usage-Driven Iteration** — subsequent gates are chosen from observed operating pain, not speculative feature completeness.

## Existing capabilities that are not next-gate greenfield work

The current source already contains and should reuse:

- Availability / occupied interval adjudication;
- HOLD / release / expiry;
- Confirm / amend / cancel;
- BLOCK / release;
- slot catalog / compatibility / calendar projection;
- Operator auth, Inquiry/HOLD, Booking workflow, BLOCK and audit;
- Trip crew/checklist and prepare/depart/return/complete backend transitions;
- PostgreSQL concurrency validation;
- finance/stock/cash candidate foundations.

Any proposal to rebuild these must prove an actual missing invariant or contract deficiency.

## Candidate pilot slice to validate in the audit

### Operational Booking Dossier

Likely minimum operational fields include:

- customer/contact name;
- contact method/value;
- party size;
- pickup/meeting point;
- sales source;
- service/customer notes;
- internal operations notes;
- currency;
- selling amount.

This is not authorization for a CRM, payment ledger, settlement system or accounting module.

### Operator Trip Desk

Likely minimum workflow:

`Today's Trips -> Service Detail -> Prepare -> Crew/Checklist -> Depart -> Return -> Complete`

The Trip backend already exists. The audit must determine the smallest extraction/UI work required.

## Explicit first-pilot exclusions

The first Real Operations Pilot must not expand into:

- ChannelHub;
- OTA adapters;
- WordPress inventory integration;
- payment gateway;
- full receivables/accounting;
- profit dashboard;
- broad stock/fuel UI expansion;
- complex SaaS administration;
- automated historical migration;
- notification center;
- reporting platform;
- maintenance management;
- second-operator onboarding;
- public semantic-version release.

## Deployment separation

Product implementation and real-operations deployment remain separate authorizations.

A future real pilot deployment must use PostgreSQL for authoritative inventory conflict adjudication. D1's fictional SQLite deployment is not a production/pilot database baseline.

Real organization, vessel, schedule, buffer, HOLD policy, Operator identities, backup/restore, production data and cutover must be separately frozen and authorized.

## Next allowed action

`AUTHORIZE_AND_RUN_MVP_READINESS_AUDIT`

The readiness audit is read-only and should preferably include an independent Codex second review. Its job is to challenge the proposed slice and identify the smallest safe delta from current `main`.

Until that audit is explicitly authorized:

- `mvp_readiness_audit_authorized=false`
- `business_code_change_authorized=false`
- `merge_authorized=false`
- `deployment_authorized=false`
- `production_enablement_authorized=false`
- `production_data_authorized=false`
- `tag_authorized=false`
- `release_authorized=false`

Current gate summary:

`D1_COMPLETE / GOVERNANCE_ALIGNED / REAL_OPERATIONS_PILOT_MVP_PROPOSED / IMPLEMENTATION_NOT_AUTHORIZED`
