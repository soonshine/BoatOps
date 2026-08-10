# BoatOps Current Gate

Updated: 2026-08-10 08:01 Asia/Bangkok

## Current authoritative decision

`WP1 = COMPLETE_MERGED / WP2 = COMPLETE_MERGED / WP3 = AUTHORIZED_TO_START`

The Owner explicitly authorized merging WP2 after primary review, and that one-time merge authorization has been consumed.

WP2 canonical evidence:

- PR: `#10`
- reviewed head: `b340e7c84480c6bcc92ae62829cad0f7f0661fec`
- primary review: `PASS`
- exact-head CI: Run `31317044622` = `SUCCESS`
- merged main: `763d22bfc4ddaf0a84df1188d50f6d40b2fa72fc`
- post-merge main CI: Run `31346016491` = `SUCCESS`

No deployment, real data, production enablement, migration/cutover, Tag or GitHub Release is authorized.

Canonical scope contract:

`docs/product/REAL_OPERATIONS_PILOT_MVP_SCOPE_FREEZE.md`

## Closed slices

### WP1 — Minimal Operational Booking Dossier

Status: `COMPLETE_MERGED`.

### WP2 — Minimal Operator Booking Workbench

Status: `COMPLETE_MERGED`.

Accepted WP2 implementation includes:

- organization-scoped Booking list/detail;
- Today / Upcoming / All views using organization-local date semantics;
- bounded date/status/reference/customer filtering;
- 25-row pagination;
- WP1 dossier display without duplicating customer data;
- direct/API-style Booking visibility when no Operator Inquiry exists;
- Booking-context Amend/Cancel adapters that reuse the existing authoritative Application actions;
- no new Booking lifecycle, no migration, no Trip mutation and no Finance expansion.

Non-blocking Pilot UX backlog:

- a Booking can remain `CONFIRMED` after the linked Trip progresses beyond `PLANNED`, so the Booking detail may still render Amend/Cancel controls even though the authoritative actions correctly reject those transitions. WP3 may add lifecycle-aware UI hints, but must not duplicate lifecycle authority in the UI.

## Current authorized slice — WP3

### Shared Trip Actions + Minimal Operator Trip Desk + Safety Repair

WP3 is now authorized to start.

Business outcome:

> An operator can execute the confirmed Trip lifecycle from BoatOps while API and Operator UI reuse one authoritative Trip mutation path, and known readiness/timestamp integrity risks are repaired without expanding the Trip status contract.

Frozen required scope:

- extract/reuse shared Application Trip actions for Prepare / Depart / Return / Complete;
- preserve existing API behavior by routing current Operations API adapters through the same shared actions;
- add a minimal organization-scoped Operator Trip Desk / Today's Trips surface;
- expose crew and checklist readiness required for Prepare/Depart;
- allow authorized Operator execution of Prepare / Depart / Return / Complete;
- preserve Trip status flow `PLANNED -> DEPARTED -> RETURNED -> COMPLETED`;
- do **not** add a `PREPARED` status;
- after a successful booking/Trip-plan amendment, invalidate stale readiness and require re-prepare before departure;
- reject future `actual_departed_at`;
- reject `actual_returned_at` earlier than departure;
- reject future `actual_returned_at`;
- ensure completion time cannot precede return time;
- retain sufficient audit/idempotency evidence for preparation and execution mutations;
- use only synthetic/fictional fixtures.

## Not authorized in WP3

Do not implement:

- a new `PREPARED` Trip state;
- a second Trip state machine;
- ChannelHub;
- OTA;
- WordPress inventory integration;
- payment gateway;
- receivables/refunds/full Finance/profit;
- broad stock/fuel UI expansion;
- CRM;
- passenger manifest expansion beyond existing minimal crew/checklist execution needs;
- maintenance/documents;
- automated historical migration;
- SaaS super-admin;
- production deployment or real data.

## Must reuse

- existing Trip tables and status semantics;
- current Operations API Trip behavior as characterization baseline;
- existing Booking Amend/Cancel actions and inventory authority;
- WP1 operational dossier and WP2 Booking Workbench read surfaces;
- Operator auth/membership;
- idempotency/audit patterns;
- PostgreSQL concurrency/inventory protection.

## Merge and deployment boundary

WP1 and WP2 merge authorizations are consumed.

Future business-code merge remains separately gated:

`merge_authorized=false`

Real Operations Deployment remains a separate later gate requiring PostgreSQL, actual organization/vessels/rules/operators, scheduler, backup/restore, health/logging, PII protection, explicit real-data authorization and cutover.

D1 SQLite is not the Pilot production database baseline.

## Authorization boundary

- readiness audit = `COMPLETE`
- MVP scope = `FROZEN`
- WP1 = `COMPLETE_MERGED`
- WP2 = `COMPLETE_MERGED`
- WP3 business-code implementation = `AUTHORIZED`
- implementation branch/commit/PR = `AUTHORIZED`
- future business-code merge = `NOT_AUTHORIZED`
- deployment = `NOT_AUTHORIZED`
- production enablement = `NOT_AUTHORIZED`
- production data = `NOT_AUTHORIZED`
- migration/cutover = `NOT_AUTHORIZED`
- Tag/Release = `NOT_AUTHORIZED`

## Next allowed action

`HERMES_IMPLEMENT_PILOT_MVP_WP3`

`WP1_COMPLETE_MERGED / WP2_COMPLETE_MERGED / WP3_AUTHORIZED / NO_DEPLOYMENT / NO_REAL_DATA / NO_FUTURE_MERGE_AUTHORIZATION`
