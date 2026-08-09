# BoatOps Current Gate

Updated: 2026-08-09 18:20 Asia/Bangkok

## Current authoritative decision

`WP1 = COMPLETE_MERGED / WP2 = AUTHORIZED_TO_START / WP3 = WAIT_FOR_WP2_REVIEW`

The Owner explicitly authorized merging WP1 after primary review, and that one-time merge authorization has been consumed.

WP1 canonical evidence:

- PR: `#8`
- reviewed head: `973e0456bf3c8672ae4ba03c61ac0a1c88cfd416`
- primary review: `PASS`
- exact-head CI: Run `31310148095` = `SUCCESS`
- merged main: `1114307d358e67d91ebcf742a26e9d7469209e67`
- post-merge main CI: Run `31310579582` = `SUCCESS`

No deployment, real data, production enablement, migration/cutover, Tag or GitHub Release is authorized.

Canonical scope contract:

`docs/product/REAL_OPERATIONS_PILOT_MVP_SCOPE_FREEZE.md`

## Closed slice — WP1

### Minimal Operational Booking Dossier

WP1 is complete and merged.

Accepted implementation includes the minimum structured operational dossier, organization scoping, safe validation, PII-safe generic audit behavior, editable dossier after Inquiry/HOLD/Confirm, and no change to authoritative Inventory/Booking lifecycle semantics.

The implementation did not create a rate snapshot or fake unknown tax/commission values.

Non-blocking Pilot UX backlog:

- selling amount is currently entered in minor units; reconsider operator-facing amount UX only from real Pilot feedback or a later bounded product decision.

## Current authorized slice — WP2

### Minimal Operator Booking Workbench

WP2 is now authorized to start.

Business outcome:

> An operator can find and manage real operational bookings directly from BoatOps without relying on the Inquiry list or an external spreadsheet.

Minimum frozen surfaces:

- organization-scoped booking list;
- booking detail;
- today / upcoming / all views or equivalent filters;
- date filter;
- status filter;
- reference/customer search;
- pagination;
- display of WP1 dossier, vessel/service timing, booking state and Trip state;
- reuse existing Amend and Cancel actions from the booking context.

Rules:

- do not introduce a second Booking lifecycle;
- derive lifecycle from existing Inquiry/HOLD/Booking/Trip relationships;
- existing authoritative Inventory actions remain the only mutation path;
- reuse WP1 dossier rather than copy customer fields into a second competing model;
- organization isolation and `booking_workflow` permission remain mandatory;
- synthetic/fictional fixtures only.

## Not authorized in WP2

Do not implement:

- WP3 Trip Desk/refactor/safety repair;
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

## Frozen later slice — WP3

### Shared Trip Actions + Minimal Operator Trip Desk + Safety Repair

Status: `WAIT_FOR_WP2_REVIEW`.

Trip status remains:

`PLANNED -> DEPARTED -> RETURNED -> COMPLETED`

No `PREPARED` state is authorized.

## Merge and deployment boundary

WP1 merge authorization is consumed.

Future business-code merge remains separately gated:

`merge_authorized=false`

Real Operations Deployment remains a separate later gate requiring PostgreSQL, actual organization/vessels/rules/operators, scheduler, backup/restore, health/logging, PII protection, explicit real-data authorization and cutover.

D1 SQLite is not the Pilot production database baseline.

## Authorization boundary

- readiness audit = `COMPLETE`
- MVP scope = `FROZEN`
- WP1 = `COMPLETE_MERGED`
- WP2 business-code implementation = `AUTHORIZED`
- WP3 start = `WAIT_FOR_WP2_REVIEW`
- implementation branch/commit/PR = `AUTHORIZED`
- future business-code merge = `NOT_AUTHORIZED`
- deployment = `NOT_AUTHORIZED`
- production enablement = `NOT_AUTHORIZED`
- production data = `NOT_AUTHORIZED`
- migration/cutover = `NOT_AUTHORIZED`
- Tag/Release = `NOT_AUTHORIZED`

## Next allowed action

`HERMES_IMPLEMENT_PILOT_MVP_WP2`

`WP1_COMPLETE_MERGED / WP2_AUTHORIZED / WP3_WAIT / NO_DEPLOYMENT / NO_REAL_DATA / NO_FUTURE_MERGE_AUTHORIZATION`
