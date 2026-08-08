# G1 Operator MVP Rule Classification

Status: G1-0 governance only
Frozen base: `3826cb2c29aea4d2b92a90e04c14f8c218fbf45c`
Scope: operator inventory/booking workflow only. No production value or deployment authority is granted here.

## 1. Classification

- **KNOWN**: established by the frozen Inventory Kernel/domain code and reusable without inventing Ayany policy.
- **CONFIGURABLE**: a generic mechanism G1 may expose or persist. Organization-specific values still need an authorized, auditable source; fixture values are never defaults.
- **OWNER_DECISION_REQUIRED**: Ayany production policy or data that only the Owner may approve. Until recorded, dependent behavior stays fail-closed, unset, unverified, or unavailable.

### KNOWN

1. BoatOps is inventory authority. Active allocations are inventory truth; the calendar is a bounded read projection, and writes are re-adjudicated transactionally.
2. Existing records and queries are organization-scoped: organizations, boats, trip templates, slot offerings, allocations, HOLDs, bookings, blocks, idempotency records, audit logs, and outbox events.
3. Allocations represent HOLD, confirmed booking, and BLOCK occupancy with service/occupied intervals. PostgreSQL has `allocations_no_active_overlap` for active allocations per organization and boat.
4. Existing actions cover availability, create/release HOLD, confirm an active unexpired HOLD, amend/reschedule, cancel, create/release BLOCK, and scheduled HOLD expiry.
5. Slot logic resolves reusable offerings, date-specific custom instances, or legacy intervals; applies resource/slot buffers; and checks physical overlap plus explicit compatibility. Unknown identified-slot pairs fail closed.
6. `SlotCalendarReadModel` supports an inclusive organization-local range up to 31 days, reports inventory revision/allocation states, and reserves nothing.
7. Existing writes use organization-scoped idempotency (`organization + operation + key`), canonical request hashes, stored-response replay, stable external references, transactions, locks, audit, inventory revisions, and outbox events.
8. API auth uses hashed Bearer tokens, trusted organization context, active-client checks, and scopes. Laravel session users exist, but operator membership/permission is not established.
9. `INQUIRY` is required by G1 but is not currently a Kernel command. It is non-allocating and consumes no availability until HOLD succeeds.

### CONFIGURABLE

Mechanisms only, not Ayany production settings:

- Organization timezone and resource status.
- Resource/slot before and after buffer fields.
- Reusable slots, date-specific custom slots, effective dates, boat applicability, activation/retirement, and pairwise ALLOW/DENY compatibility.
- HOLD TTL/expiry policy, if the server resolves it from approved organization policy rather than trusting arbitrary UI input.
- Minimal operator roles and server-side permissions.
- Exactly 7-day and 30-day operator views using the existing maximum-31-day projection.
- BLOCK reason/interval through existing allocation conflict authority.
- Fictional organizations, resources, products/templates, slots, inquiries, HOLDs, bookings, and blocks for isolated fixtures/tests.

Missing required configuration must fail closed or be visibly unverified.

### OWNER_DECISION_REQUIRED

These concrete Ayany production inputs are expressly unresolved and must not be guessed:

1. Exact **Plan A schedules**.
2. Exact **Plan B schedules**.
3. Exact **buffer** values, including resource, slot, turnaround, before, and after buffers.
4. Exact **HOLD TTL**, extension, and re-HOLD policy.
5. Allowed/forbidden **slot combinations/exclusions**, including each compatibility pair.
6. **Weather rules**: trigger/evidence, timing, block/unblock/override authority, and workflow consequences. Existing `WEATHER` reason code is only a mechanism, not policy.
7. **Custom-slot rules**: creation/approval authority, timing/duration/buffer ranges, boat applicability, cutoffs, and compatibility.
8. Production operator identities, memberships, role assignments, approval boundaries, and revocation.
9. Any production customer/order/product/price content; real data is excluded from G1.

Owner decisions require dated, attributable approval and must become visible configuration/policy, never hidden UI rules.

## 2. Fixture/test warning

All fixtures, seeds, demos, examples, and test values are **fictional and are not production defaults**. They are not recommendations, inferred Ayany rules, or evidence for Plan A/Plan B schedules, buffers, HOLD TTL, compatibility, weather, or custom-slot policy. Production must not silently fall back to fixtures.

## 3. Inventory Kernel reuse map

| G1 capability | Existing reuse target | Mandatory boundary |
|---|---|---|
| Resource/product/availability | `boats`, `trip_templates`, `SlotCatalogService`, `SlotIntervalResolver`, `SlotAvailabilityService` | Organization-scoped application reads; no calculation in UI/browser. |
| 7/30-day calendar | `SlotCalendarReadModel`, `ScheduleController::calendar` behavior | Projection only; writes re-check inventory. |
| HOLD | `InventoryCommandController::createHold`; `holds`, `allocations` | One shared application action for API/UI. |
| HOLD release/expiry | `releaseHold`, `ExpireHolds`, `routes/console.php` scheduler | Same state/inventory invariants and audited transitions. |
| CONFIRM | `confirmBooking`; booking/trip/allocation transaction | Preserve active/unexpired HOLD and lock rules. |
| Amendment/reschedule | `amendBooking`, resolver, availability service | Exclude current allocation and re-adjudicate transactionally. |
| Cancellation | `cancelBooking` | Preserve booking/allocation/trip, revision, event, audit semantics. |
| BLOCK/unblock | `OperationsCommandController::createBlock`/`releaseBlock` | Preserve isolation, overlap protection, idempotency, revision, events, audit. |
| Slot combinations | `SlotCompatibilityService` | Explicit rules only; unknown pairs fail closed. |
| Calendar status | `SlotAvailabilityService::calendarDecision` | Render HELD/CONFIRMED/BLOCKED without write authority. |
| Concurrency | organization/allocation locks, transactions, PostgreSQL exclusion constraint | Database is final arbiter. |
| Idempotency/audit | `idempotency_keys`, `audit_logs`, `outbox_events`, inventory revision | Add operator actor support without weakening scope/replay. |

```text
Inventory Provider API -> shared application actions -> Inventory Kernel/domain services -> database constraints
Operator UI -> the same application actions -> the same Inventory Kernel/domain services -> database constraints
Scheduled/background Jobs -> the same application actions -> the same Inventory Kernel/domain services -> database constraints
```

API / Operator UI / Jobs must use the same application/domain actions for the same transition. Later extraction of controller-contained transactions needs characterization tests preserving existing contracts. UI may format/render only; it must not duplicate overlap, buffer, compatibility, HOLD expiry, booking transition, BLOCK, idempotency, or organization-isolation rules.

`INQUIRY` is the minimum new non-inventory workflow: intent plus candidate resource/product/date/slot, no allocation. Promotion invokes shared HOLD and may fail after transactional re-adjudication.

## 4. Minimum G1 data/auth/audit changes

Only these additions are proposed; exact schema remains phase-reviewed:

1. **Operator organization membership**: associate a session-authenticated `users` identity with one organization and minimal role/status. Never derive organization from mutable request input.
2. **Basic permission**: minimally distinguish calendar/read, booking operations, and BLOCK/unblock, enforced server-side. Production assignments require Owner decision.
3. **INQUIRY record**: organization-scoped ID, fictional/customer-neutral reference fields, status, candidate resource/product/date/slot, notes, actors/timestamps, and optional resulting HOLD reference. No allocation or price/payment fields.
4. **Operator audit attribution**: mutation audit records organization, actor type/id, action, object, before/after, reason when applicable, correlation context, and time. Audit is append-only to application users.
5. **UI idempotency entry**: every mutation gets a stable operation-scoped key before submission and uses existing canonical replay/conflict semantics. Retry/double-click cannot duplicate records.

No new inventory table, alternate availability cache, duplicate booking state machine, pricing model, or broad auth/policy framework.

### Organization-isolation invariant
- Auth establishes trusted organization before lookup or replay.
- Every query and uniqueness/idempotency scope includes organization; foreign IDs return non-disclosing authorization failure.
- Memberships, resources, products/templates, inquiries, allocations, HOLDs, bookings, blocks, audit, and events cannot cross organizations.

### Idempotency invariant
- Every mutating API/UI/retryable Job action has deterministic operation identity and stable key.
- Same organization/operation/key and canonical payload replays first completed result; changed payload conflicts; auth precedes replay.
- External references remain organization-unique/stable. Retention exceeds the longest retry window; production duration needs approval.

### Concurrency invariant
- Calendar/availability is advisory and revisioned; mutations always resolve/check again.
- Conflicting HOLD, CONFIRM, amendment, and BLOCK serialize through shared actions, locks, transactions, and constraints.
- At most one conflicting active allocation wins; stale UI cannot override inventory.
- CONFIRM-versus-expiry locks/re-reads state; one valid terminal transition wins with consistent allocation, audit, revision, and event evidence.

## 5. Reviewable implementation phases

### G1-1 — Operator login/access and basic permission

Session login/logout, membership, active-user checks, minimal read/booking/block permissions, and isolation tests. No production identities/assignments.

### G1-2 — 7/30-day calendar and resource/product/availability display

Exactly 7-day and 30-day views of organization resources, products/templates, slots, allocations, states, buffers, conflicts, and revision via existing domain reads. No browser adjudication.

### G1-3 — INQUIRY

Minimal non-allocating create/read/update, audit, fictional fixtures, isolation, permission, and idempotency tests. HOLD entry reserves nothing until HOLD succeeds.

### G1-4 — HOLD and HOLD expiry infrastructure

Shared HOLD action for API/UI, release as required, approved-policy TTL resolution, scheduled expiry, audit/outbox/revision, and fictional retry, duplicate, expiry, and race tests. No production TTL.

### G1-5 — CONFIRM, amendment/reschedule, cancellation

Shared actions preserving Kernel contracts; deterministic conflict display; idempotency, stale calendar, isolation, and CONFIRM/expiry race tests.

### G1-6 — BLOCK/unblock

Permission-gated existing allocation authority, audited actor/reason, fictional conflict and BLOCK/HOLD concurrency tests. Weather policy stays unresolved.

### G1-7 — Audit trail, idempotency, concurrency protection

Organization-filtered read-only audit trail; actor/correlation evidence across UI, API, Jobs, revisions, audit, outbox; PostgreSQL race/constraint and replay/conflict coverage.

### G1-8 — Fictional fixtures, tests, CI

Clearly fictional fixtures with no production fallback; unit, feature, permission, isolation, lifecycle, expiry, idempotency, concurrency tests; CI for relevant PHP/JS/contract suites only, with no deployment/release step.

## 6. Hard exclusions

- Fuel.
- Stock/warehouse.
- Procurement.
- Expense.
- Payment.
- Refund.
- Cash.
- Profit.
- Finance.
- Google Sheet migration.
- ChannelHub.
- OTA.
- WordPress.
- Payment gateway.
- Real customer/order/price data.
- Production deployment or server access.
- Tag.
- Release.
- Merge `main`.
- Unrelated refactor.

Capabilities outside the phases and claims that demos/fixtures are approved Ayany policy are also excluded.

## 7. G1-0 acceptance criteria

1. Sole frozen-base change is `docs/g1/G1_OPERATOR_MVP_RULES.md`.
2. Inputs use KNOWN, CONFIGURABLE, OWNER_DECISION_REQUIRED.
3. Exact Plan A/Plan B schedules, buffer, HOLD TTL, slot combinations/exclusions, weather rules, custom-slot rules remain Owner decisions with no production values.
4. Fixtures/tests are fictional, not production defaults.
5. Reuse map makes API / Operator UI / Jobs converge on shared actions with no UI rule duplication.
6. Minimum data/auth/audit changes and isolation, idempotency, concurrency invariants are stated.
7. Phases cover only authorized capabilities, fictional fixtures, tests, CI.
8. All hard exclusions are recorded.
9. No application code, migration, test, CI, dependency, or configuration change; no commit, push, PR, merge, tag, release, deployment, server access, or real data.

## 8. Change control

No OWNER_DECISION_REQUIRED item may be guessed, inferred from demos/fixtures, copied from an unapproved source, or turned into a production default by a developer or agent. If implementation reaches an unresolved Owner decision, dependent work stops and records the missing decision. Change needs explicit attributable Owner approval, this record or a linked approved decision record updated, tests for approved behavior, and normal review. Silence, historical tests, UI labels, and existing enums are not approval.
