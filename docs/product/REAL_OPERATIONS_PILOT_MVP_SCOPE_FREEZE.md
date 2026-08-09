# BoatOps Real Operations Pilot MVP — Scope Freeze

Status: `SCOPE_FROZEN / IMPLEMENTATION_NOT_AUTHORIZED`

Frozen: 2026-08-09 17:28 Asia/Bangkok

Canonical product baseline: `185ebaaac7c5d9f2435eea9faff2f6beeb6f78fe`

D1 deployed product source: `f9503b598b174b7a6891fcde0d984514a3cd0fcd`

## 1. Decision

The Real Operations Pilot MVP scope is now frozen after:

1. the project roadmap was synchronized to GitHub;
2. an independent read-only Codex readiness audit was completed;
3. the primary reviewer independently re-checked the material findings against canonical `main`.

Decision: `PILOT_MVP_SCOPE_FROZEN`

Implementation remains separately gated: `BUSINESS_CODE_CHANGE_NOT_AUTHORIZED`

Primary objective:

> Reach the smallest safe version that can be used for real daily vessel operations, then iterate from actual operating feedback.

Time-to-real-use takes priority over feature completeness.

## 2. Independent audit findings accepted

The review confirms these material gaps:

- the current Inquiry/Booking model is too thin to act as a real operational order dossier;
- there is no dedicated Operator booking list/detail workbench for today/upcoming/all operational bookings;
- Trip prepare/depart/return/complete backend behavior exists, but the mutation logic is still controller-contained and no Operator Trip Desk exists;
- current Trip preparation remains `PLANNED`; a successful booking amendment can change boat/time while leaving previously stored crew/checklist readiness in place;
- depart/return accept caller-supplied timestamps without rejecting future timestamps, while complete uses server `now`, so temporal ordering can become inconsistent;
- Inventory, Schedule, HOLD, Confirm, Amend, Cancel and BLOCK foundations are existing assets and must not be rebuilt.

## 3. Reviewer correction to the independent audit

The independent audit proposed adding a new Trip status `PREPARED`. That proposal is **not accepted for this MVP**.

Reason:

- the current Operations API contract intentionally defines `prepare` as saving preparation for a Trip whose status remains `PLANNED`;
- the current prepare response contract fixes `status=PLANNED`;
- depart is defined as departing a fully prepared planned Trip;
- adding `PREPARED` would expand the Trip state machine and API contract beyond the smallest fix required for the Pilot.

Frozen MVP rule:

> Preparation remains readiness data attached to a `PLANNED` Trip. If the booking/Trip plan is amended, previously stored readiness must be invalidated atomically so departure cannot reuse readiness created for an obsolete boat/time plan.

No new Trip state is authorized by this gate.

## 4. Frozen implementation scope

### WP1 — Minimal Operational Booking Dossier

Business outcome: an operator can understand and execute a confirmed booking without consulting an external spreadsheet for the minimum service instructions.

Minimum structured capability:

- customer/contact name;
- contact method/value;
- party size;
- pickup / meeting point;
- optional dropoff / service location;
- sales source / agent reference where applicable;
- customer/service notes;
- internal operations notes;
- optional currency and selling amount.

Rules:

- organization scoping is mandatory;
- raw contact values must not be copied into generic audit JSON;
- existing `booking_workflow` permission may be reused for the Pilot; no broad CRM permission model is required;
- optional selling amount/currency must not trigger a redesign of Finance, tax, commission, receivables or settlement semantics;
- an unpriced booking remains allowed if current business workflow requires it;
- `notes` alone is not sufficient implementation of the structured dossier.

Explicit non-goals: CRM/customer master, complex passenger manifest, payment ledger, receivables, refund workflow, commission settlement, accounting.

### WP2 — Minimal Operator Booking Workbench

Business outcome: an operator can find and manage real operational bookings directly from BoatOps.

Minimum surfaces:

- organization-scoped booking list;
- booking detail;
- today / upcoming / all views or equivalent filters;
- date filter;
- status filter;
- reference/customer search;
- pagination;
- display of dossier, vessel/service timing, booking state and Trip state;
- reuse existing Amend and Cancel actions from the booking context.

Rules:

- do not introduce a second booking lifecycle;
- derive lifecycle from existing Inquiry/HOLD/Booking/Trip relationships;
- existing authoritative Inventory actions remain the only mutation path.

Explicit non-goals: analytics dashboard, reporting platform, CRM pipeline, notification center, export center.

### WP3 — Shared Trip Actions + Minimal Operator Trip Desk + Safety Repair

Business outcome: an operator can execute today's Trip lifecycle safely through BoatOps using the existing Trip engine.

Architecture:

- extract existing prepare/depart/return/complete transaction/business behavior into shared `app/Application/Trips/*` actions;
- API and Operator UI must call the same authoritative actions;
- Controllers remain authorization/validation/HTTP adapters;
- do not create a parallel Trip state machine.

Trip status rule for this MVP:

`PLANNED -> DEPARTED -> RETURNED -> COMPLETED`

Preparation is readiness attached to `PLANNED`; there is no `PREPARED` status.

Required safety behavior:

1. `prepare` stores the current crew/checklist readiness for the current Trip plan.
2. A successful amend of a `PLANNED` booking/Trip must atomically invalidate any existing crew/checklist readiness for that Trip.
3. After such an amend, departure must fail until the Trip is prepared again for the amended plan.
4. Depart requires current complete readiness.
5. Departed time must not be in the future relative to server time.
6. Returned time must not be before departed time and must not be in the future relative to server time.
7. Complete may occur only from `RETURNED`; resulting `completed_at` must not precede `actual_returned_at`.
8. Re-prepare must leave sufficient audit evidence of changed crew/checklist operational identifiers and completion state without exposing unrelated PII/secrets.

Minimum Operator surfaces:

- Today's Trips;
- Trip detail linked to booking/service dossier;
- crew/checklist preparation;
- Prepare;
- Depart;
- Return;
- Complete.

Explicit non-goals: new Trip engine, dispatch optimization, maintenance, vessel documents, advanced manifest, fuel/stock UI expansion, notifications.

## 5. Required acceptance tests

### Dossier / Booking

- dossier validation;
- organization isolation;
- permission enforcement;
- raw contact value absent from generic audit payloads;
- booking list pagination;
- today/upcoming/all behavior;
- date/status/reference/customer filtering;
- booking detail cross-organization access rejection;
- existing HOLD/Confirm/Amend/Cancel behavior remains unchanged except where explicitly integrated with the dossier.

### Trip

- characterization tests preserve existing API behavior while moving mutations to shared actions;
- API and Operator adapters use the shared Trip actions;
- prepare on a planned Trip stores readiness while Trip status remains `PLANNED`;
- prepare -> amend invalidates readiness atomically;
- prepare -> amend -> depart fails until re-prepare;
- re-prepare -> depart succeeds when required checklist is complete;
- future depart time rejected;
- returned time before depart rejected;
- future return time rejected;
- complete before return rejected;
- completed timestamp cannot precede returned timestamp;
- repeated idempotent commands remain idempotent;
- terminal-state/race tests cover cancel/depart, amend/prepare and completion boundaries as applicable.

### Regression

- all existing PHPUnit tests pass;
- contract validation passes;
- migration round-trip passes;
- PostgreSQL concurrency gates pass;
- synthetic/fictional fixtures only in automated tests.

## 6. Existing capabilities that must be reused

Do not reimplement without explicit new evidence:

- Availability and occupied-interval adjudication;
- HOLD / release / expiry;
- Booking Confirm / Amend / Cancel;
- BLOCK / release;
- Inventory revision;
- PostgreSQL exclusion/concurrency protection;
- idempotency;
- audit/outbox foundations;
- slot catalog / compatibility / custom slots / calendar projection;
- Operator login/membership/calendar/Inquiry workflow;
- existing Trip schema and core execution semantics;
- existing finance/stock/rate foundations where useful, without expanding the Pilot into full Finance.

## 7. Explicit Pilot exclusions

The frozen Pilot scope excludes ChannelHub, OTA adapters, WordPress inventory integration, payment gateway, complete receivables/accounting/refunds/profit, broad stock/fuel UI expansion, CRM, complex SaaS admin, automated historical migration, notification center, reporting platform, maintenance management, vessel document management, complex passenger manifest, second-company onboarding, and public semantic-version Release.

## 8. Deployment remains a separate gate

This scope freeze does not authorize real deployment.

A later Real Operations Deployment gate must separately prove at minimum:

- PostgreSQL authoritative database;
- actual operating organization;
- actual vessels/resources and service windows;
- actual buffers and HOLD policy;
- real Operator identities/permissions;
- HOLD expiry scheduler operation;
- backup and actual restore;
- health/logging/runtime checks;
- PII handling and backup protection;
- explicit real-data and cutover authorization;
- physical isolation from fictional Demo data.

Historical automated migration is not required for the first Pilot unless later evidence proves otherwise.

## 9. Authorization boundary

- MVP readiness audit: `COMPLETE`
- MVP implementation scope: `FROZEN`
- business-code change: `NOT_AUTHORIZED`
- business-code merge: `NOT_AUTHORIZED`
- deployment: `NOT_AUTHORIZED`
- production enablement: `NOT_AUTHORIZED`
- production data: `NOT_AUTHORIZED`
- migration/cutover: `NOT_AUTHORIZED`
- Tag/Release: `NOT_AUTHORIZED`

Next decision: `OWNER_AUTHORIZE_PILOT_MVP_IMPLEMENTATION`

`PILOT_MVP_SCOPE_FROZEN / NO_CODE_CHANGE / NO_DEPLOYMENT / NO_REAL_DATA`
