# BoatOps Current Gate

Updated: 2026-08-09 17:40 Asia/Bangkok

## Current authoritative decision

`REAL_OPERATIONS_PILOT_MVP_IMPLEMENTATION = AUTHORIZED`

The Owner has authorized business-code implementation of the already frozen Real Operations Pilot MVP scope.

This authorization does **not** authorize business-code merge, deployment, production enablement, real data, migration/cutover, Tag or GitHub Release.

Canonical implementation baseline:

`ae62d26f418ab841a67497387d03a904e33e9064`

Canonical scope contract:

`docs/product/REAL_OPERATIONS_PILOT_MVP_SCOPE_FREEZE.md`

## Execution discipline

Implementation is staged:

`WP1 -> primary review -> WP2 -> primary review -> WP3 -> primary review`

Only WP1 is authorized to start now.

WP2 and WP3 remain inside the approved MVP scope, but executors must not begin them until the primary reviewer closes the preceding slice.

## Current authorized slice — WP1

### Minimal Operational Booking Dossier

Implement only the frozen minimum structured service/order information needed for real daily execution while preserving existing authoritative Inventory/Booking actions.

Required minimum:

- customer/contact name;
- contact method and contact value;
- party size;
- pickup / meeting point;
- optional dropoff / service location;
- sales source / optional agent or partner reference;
- customer/service notes separated from internal operations notes;
- optional currency and selling amount using the existing rate-snapshot foundation where appropriate.

Required properties:

- organization scoped;
- safe validation;
- explicit PII handling;
- ordinary audit output must not leak contact values;
- existing Inventory Provider API contract must not be broken;
- existing HOLD / Confirm / Amend / Cancel authority must be reused;
- synthetic/fictional fixtures only.

## Not authorized in WP1

Do not implement:

- Booking Workbench (WP2);
- Trip Desk or Trip refactor (WP3);
- new `PREPARED` Trip state;
- ChannelHub;
- OTA;
- WordPress inventory integration;
- payment gateway;
- receivables/refunds/full Finance/profit;
- CRM;
- complex passenger manifest;
- maintenance/documents;
- automated historical migration;
- SaaS super-admin;
- production deployment or real data.

## Frozen later slices

### WP2 — Minimal Operator Booking Workbench

Frozen, but `WAIT_FOR_WP1_REVIEW`.

### WP3 — Shared Trip Actions + Minimal Operator Trip Desk + Safety Repair

Frozen, but `WAIT_FOR_WP2_REVIEW`.

Trip status remains:

`PLANNED -> DEPARTED -> RETURNED -> COMPLETED`

No `PREPARED` state is authorized.

A successful Trip-plan amendment must invalidate stored readiness before later departure.

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

## Deployment separation

Real Operations Deployment remains a separate later gate requiring PostgreSQL, actual organization/vessels/rules/operators, scheduler, backup/restore, health/logging, PII protection, explicit real-data authorization and cutover.

D1 SQLite is not the Pilot production database baseline.

## Authorization boundary

- readiness audit = `COMPLETE`
- MVP scope = `FROZEN`
- WP1 business-code implementation = `AUTHORIZED`
- WP2 start = `WAIT_FOR_WP1_REVIEW`
- WP3 start = `WAIT_FOR_WP2_REVIEW`
- implementation branch/commit/PR = `AUTHORIZED`
- business-code merge = `NOT_AUTHORIZED`
- deployment = `NOT_AUTHORIZED`
- production enablement = `NOT_AUTHORIZED`
- production data = `NOT_AUTHORIZED`
- migration/cutover = `NOT_AUTHORIZED`
- Tag/Release = `NOT_AUTHORIZED`

## Next allowed action

`HERMES_IMPLEMENT_PILOT_MVP_WP1`

`PILOT_MVP_IMPLEMENTATION_AUTHORIZED / WP1_ONLY / NO_DEPLOYMENT / NO_REAL_DATA / NO_MERGE`
