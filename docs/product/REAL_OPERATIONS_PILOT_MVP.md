# BoatOps Real Operations Pilot MVP Roadmap

Status: `PROPOSED / GOVERNANCE_SYNCED / BUSINESS_CODE_NOT_AUTHORIZED`

Updated: 2026-08-09 16:52 Asia/Bangkok

## 1. Goal

Move BoatOps from a fictional Demo-validated alpha into the smallest practical version that can support a real vessel operator's daily workflow, then iterate from actual usage.

Primary product principle for this phase:

> **Time-to-real-use takes priority over feature completeness.**

This roadmap does not authorize business-code changes, deployment, production data, migration, Tag, Release, ChannelHub, OTA, payment integration, or any external-channel work.

BoatOps remains a reusable, organization-scoped product. The first real operations pilot must not hard-code Ayany, vessel ownership, or deployment-specific rules into core product logic.

## 2. Existing capabilities to reuse

The current reviewed source already includes significant foundations that must not be reimplemented:

- Availability and whole-vessel occupied-interval adjudication;
- HOLD, release, expiry;
- Booking confirm, amend, cancel;
- BLOCK and release;
- Inventory revision, audit, idempotency and outbox foundations;
- Slot catalog, compatibility and schedule/calendar projection;
- Operator authentication, membership permissions, calendar, Inquiry/HOLD, Booking workflow, BLOCK and audit surfaces;
- Trip execution backend with crew/checklist and prepare/depart/return/complete transitions;
- PostgreSQL exclusion/concurrency validation in CI;
- operations-finance, fuel, expense, stock, cash-posting and reversal foundations.

The next implementation must prefer extraction/reuse of existing application/domain behavior over parallel business-rule paths.

## 3. Six-step realization path

### Step 1 — MVP Readiness Audit

Purpose: determine the smallest delta between current `main` and a usable Real Operations Pilot.

Preferred independent second review: Codex, read-only only.

Audit questions:

- Which required pilot capabilities already exist and are tested?
- Which operational-order fields are structurally missing?
- Which Trip capabilities already exist in backend but lack shared Application Actions or Operator UI?
- Are there any blockers that would prevent a small real pilot?
- What must explicitly not be rebuilt?

Output: one bounded MVP implementation slice. No code changes in this step.

### Step 2 — Minimal Operational Booking Dossier

Purpose: make a confirmed booking understandable and actionable by an operator without relying on an external spreadsheet for basic service instructions.

Candidate minimum information:

- customer/contact name;
- contact method/value;
- party size;
- pickup / meeting point;
- sales source;
- customer/service notes;
- internal operations notes;
- currency;
- selling amount.

Required surfaces should stay minimal:

- booking/order list;
- today/upcoming view or equivalent filter;
- booking/order detail;
- existing HOLD / Confirm / Amend / Cancel workflow reuse.

This is not a CRM, payment system, accounting system, or partner settlement system.

### Step 3 — Minimal Operator Trip Desk

Purpose: expose the existing Trip execution engine to the operator without creating a second Trip state machine.

Minimum workflow:

`Today's Trips -> Booking/Service Detail -> Prepare -> Crew/Checklist -> Depart -> Return -> Complete`

Architecture rule:

- extract/reuse shared Trip Application Actions where current transaction/business logic is still trapped in API controllers;
- API and Operator UI must call the same authoritative mutation path;
- do not redesign Trip states unless the audit proves a missing invariant.

### Step 4 — Real Operations Deployment Readiness

Purpose: prepare a separate production/pilot deployment gate after the product slice is accepted.

Minimum deployment concerns:

- PostgreSQL as the authoritative real-operations database;
- actual operating organization configuration;
- actual vessels/resources and service windows;
- actual buffer policy;
- actual HOLD TTL/policy;
- real Operator identities and permissions;
- backups and proven restore procedure;
- health/logging/runtime checks;
- explicit production-data authorization.

D1 fictional SQLite is not the production/pilot database baseline.

### Step 5 — Small Real Cutover

Purpose: start using BoatOps with the smallest safe operational cutover.

Preferred approach unless later evidence requires otherwise:

- historical records remain in the previous system for lookup;
- only future still-active bookings are manually entered if needed;
- from a defined cutover point, new operational bookings are managed in BoatOps;
- no automated historical migration is required for the first pilot.

Cutover remains a separate data/deployment authorization.

### Step 6 — Usage-Driven Iteration

Purpose: let actual operating pain determine subsequent product gates.

Examples of later candidates, only if real use demonstrates need:

- payments / outstanding / refunds;
- dispatch / pickup / crew coordination;
- revenue + cost + commission + profit;
- richer permissions / notifications / audit;
- maintenance, documents, passenger manifest;
- broader reporting and integrations.

No later feature is pre-authorized by this roadmap.

## 4. Explicit first-pilot exclusions

The first pilot should not expand into:

- ChannelHub;
- OTA adapters;
- WordPress inventory integration;
- payment gateway;
- full receivables/accounting;
- profit dashboard;
- broad stock/fuel UI expansion;
- complex SaaS admin;
- automated historical Google Sheet migration;
- notification center;
- reporting platform;
- maintenance management;
- second-operator onboarding;
- public semantic-version release.

Existing finance/stock code remains an available foundation but is not the first-pilot focus.

## 5. Gate discipline

This document records product direction, not implementation authorization.

Current authoritative gate remains:

`D1_COMPLETE / GOVERNANCE_ALIGNED / FICTIONAL_DEMO_ONLY`

Next product-gate candidate:

`REAL_OPERATIONS_PILOT_MVP`

Candidate status:

`PROPOSED / NOT_AUTHORIZED`

Before implementation:

1. run the bounded MVP readiness audit;
2. reconcile the audit with current source and tests;
3. freeze exact acceptance criteria and exclusions;
4. obtain Owner authorization for business-code change and merge.

Deployment, production data, migration/cutover, Tag and Release remain separately authorized actions.
