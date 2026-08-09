# BoatOps Current Gate

Updated: 2026-08-09 17:28 Asia/Bangkok

## Current authoritative decision

`REAL_OPERATIONS_PILOT_MVP_SCOPE = FROZEN / APPROVED_SCOPE_ONLY`

Implementation is **not authorized**.

D1 remains complete and frozen as fictional Demo validation only.

- D1 deployed product source: `f9503b598b174b7a6891fcde0d984514a3cd0fcd`
- Canonical main at scope-freeze start: `185ebaaac7c5d9f2435eea9faff2f6beeb6f78fe`
- Real data: `NONE`
- Production PostgreSQL: `NOT_ENABLED`
- Tag / GitHub Release: `NONE`

Canonical scope contract:

`docs/product/REAL_OPERATIONS_PILOT_MVP_SCOPE_FREEZE.md`

## Audit closure

The bounded read-only MVP readiness audit is complete.

Independent Codex decision:

`PILOT_MVP_SCOPE_CAN_BE_FROZEN`

Primary reviewer decision:

`APPROVE_WITH_CORRECTION`

Accepted correction:

> Do not add a new `PREPARED` Trip status in the Pilot. Existing Operations API semantics keep preparation attached to a `PLANNED` Trip. A successful amendment must invalidate stored readiness atomically and require re-prepare before departure.

## Frozen work packages

### WP1 — Minimal Operational Booking Dossier

Structured minimum service/order data for real daily execution, organization-scoped and PII-safe.

### WP2 — Minimal Operator Booking Workbench

Booking list/detail with today/upcoming/all, basic filters, pagination and reuse of existing authoritative Booking actions.

### WP3 — Shared Trip Actions + Minimal Operator Trip Desk + Safety Repair

Extract current Trip mutations into shared Application Actions, add Operator Trip surfaces, invalidate readiness after amendment, and enforce safe actual timestamp ordering.

Trip status remains:

`PLANNED -> DEPARTED -> RETURNED -> COMPLETED`

No `PREPARED` status is authorized.

## Must reuse

- Availability / occupied intervals;
- HOLD / release / expiry;
- Confirm / Amend / Cancel;
- BLOCK / release;
- Inventory revision / idempotency / audit / outbox;
- Slot catalog / compatibility / calendar projection;
- Operator auth/membership and existing Inquiry workflow;
- existing Trip schema/core semantics;
- PostgreSQL exclusion/concurrency protection.

## Explicit exclusions

First Pilot excludes ChannelHub, OTA, WordPress inventory integration, payment gateway, complete Finance/accounting/refunds/profit, broad stock/fuel UI expansion, CRM, complex SaaS admin, automated historical migration, notification/reporting platforms, maintenance, vessel documents, complex manifest, second-company onboarding, and public Release.

## Deployment separation

Scope freeze does not authorize deployment.

Real Operations Deployment remains a later gate requiring PostgreSQL, actual organization/vessels/rules/operators, scheduler, backup/restore, health/logging, PII protection, explicit real-data authorization and cutover.

D1 SQLite is not the Pilot production database baseline.

## Authorization boundary

- readiness audit = `COMPLETE`
- MVP scope = `FROZEN`
- business code change = `NOT_AUTHORIZED`
- business code merge = `NOT_AUTHORIZED`
- deployment = `NOT_AUTHORIZED`
- production enablement = `NOT_AUTHORIZED`
- production data = `NOT_AUTHORIZED`
- migration/cutover = `NOT_AUTHORIZED`
- Tag/Release = `NOT_AUTHORIZED`

## Next allowed action

`OWNER_AUTHORIZE_PILOT_MVP_IMPLEMENTATION`

`PILOT_MVP_SCOPE_FROZEN / IMPLEMENTATION_NOT_AUTHORIZED / NO_DEPLOYMENT / NO_REAL_DATA`
