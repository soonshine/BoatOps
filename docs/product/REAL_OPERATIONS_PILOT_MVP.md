# BoatOps Real Operations Pilot MVP Roadmap

Status: `SCOPE_FROZEN / BUSINESS_CODE_NOT_AUTHORIZED`

Updated: 2026-08-09 17:28 Asia/Bangkok

## 1. Goal

Move BoatOps from a fictional Demo-validated alpha into the smallest practical version that can support a real vessel operator's daily workflow, then iterate from actual usage.

Primary principle:

> **Time-to-real-use takes priority over feature completeness.**

BoatOps remains reusable and organization-scoped. The first real pilot must not hard-code Ayany, vessel ownership, or deployment-specific business rules into core product logic.

Canonical frozen implementation contract:

`docs/product/REAL_OPERATIONS_PILOT_MVP_SCOPE_FREEZE.md`

## 2. Existing capabilities to reuse

The reviewed source already contains foundations that must not be rebuilt without evidence of a missing invariant:

- Availability and whole-vessel occupied-interval adjudication;
- HOLD / release / expiry;
- Booking Confirm / Amend / Cancel;
- BLOCK / release;
- Inventory revision, audit, idempotency and outbox foundations;
- Slot catalog, compatibility and schedule/calendar projection;
- Operator auth/membership, calendar, Inquiry/HOLD, Booking workflow, BLOCK and audit;
- Trip crew/checklist plus prepare/depart/return/complete backend behavior;
- PostgreSQL exclusion/concurrency validation;
- finance/stock/cash/rate foundations for later reuse.

## 3. Six-step realization path

### Step 1 — MVP Readiness Audit

Status: `COMPLETE`

An independent Codex read-only audit concluded `PILOT_MVP_SCOPE_CAN_BE_FROZEN`. Primary review independently verified the material findings.

Primary review accepted one correction: the Pilot will **not** add a new `PREPARED` Trip status. Preparation remains readiness attached to a `PLANNED` Trip; amendment must invalidate old readiness and require re-prepare before departure.

### Step 2 — Minimal Operational Booking Dossier + Booking Workbench

Status: `SCOPE_FROZEN / IMPLEMENTATION_NOT_AUTHORIZED`

Frozen outcome:

- minimum structured customer/contact and service-execution data;
- party size and pickup/meeting information;
- separated service/internal notes;
- optional selling amount/currency without expanding into full Finance;
- organization-scoped paginated booking list/detail;
- today/upcoming/all and basic search/filtering;
- reuse of existing authoritative booking actions.

### Step 3 — Minimal Operator Trip Desk

Status: `SCOPE_FROZEN / IMPLEMENTATION_NOT_AUTHORIZED`

Frozen outcome:

`Today's Trips -> Booking/Service Detail -> Prepare -> Crew/Checklist -> Depart -> Return -> Complete`

Architecture:

- extract existing Trip mutations into shared `app/Application/Trips/*` actions;
- API and Operator UI use the same actions;
- no parallel Trip state machine;
- keep Trip status flow `PLANNED -> DEPARTED -> RETURNED -> COMPLETED`;
- successful amendment invalidates stored Trip readiness;
- enforce actual timestamp ordering and reject future actual depart/return times.

### Step 4 — Real Operations Deployment Readiness

Status: `NOT_AUTHORIZED`

Separate future gate for:

- PostgreSQL authoritative database;
- actual organization/vessels/service windows;
- actual buffers/HOLD policy;
- real Operator identities;
- scheduler;
- backup/restore;
- health/logging;
- PII protection;
- explicit production-data authorization.

D1 fictional SQLite is not the real Pilot database baseline.

### Step 5 — Small Real Cutover

Status: `NOT_AUTHORIZED`

Preferred first-pilot approach unless later evidence requires otherwise:

- keep historical records in the previous system for lookup;
- manually enter only future active bookings if needed;
- from an explicit cutover point, manage new operational bookings in BoatOps;
- no automated historical migration is required for the first Pilot.

### Step 6 — Usage-Driven Iteration

Status: `FUTURE / NOT_AUTHORIZED`

Later work is chosen from observed operating pain, for example payment/outstanding/refunds, dispatch, profit, richer permissions, notifications, maintenance, documents or reporting.

## 4. Explicit first-pilot exclusions

The Pilot scope excludes:

- ChannelHub;
- OTA adapters;
- WordPress inventory integration;
- payment gateway;
- complete receivables/accounting/refunds/profit;
- broad stock/fuel UI expansion;
- CRM;
- complex SaaS admin;
- automated historical migration;
- notification/reporting platforms;
- maintenance management;
- vessel document management;
- complex passenger manifest;
- second-company onboarding;
- public semantic-version Release.

## 5. Gate discipline

Current state:

- `D1_COMPLETE`
- `MVP_READINESS_AUDIT_COMPLETE`
- `REAL_OPERATIONS_PILOT_MVP_SCOPE_FROZEN`
- `BUSINESS_CODE_NOT_AUTHORIZED`
- `DEPLOYMENT_NOT_AUTHORIZED`
- `NO_REAL_DATA`

Next decision:

`OWNER_AUTHORIZE_PILOT_MVP_IMPLEMENTATION`

Deployment, production data, migration/cutover, Tag and Release remain separately authorized actions.
