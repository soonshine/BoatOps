# BoatOps Real Operations Path

Status: `ACTIVE_PRODUCT_PATH`

Updated: 2026-08-10 11:26 Asia/Bangkok

This path replaces module-driven feature planning. The filename remains for stable links; `REAL_OPERATIONS_PILOT_MVP_SCOPE_FREEZE.md` is now the historical WP1-WP3 implementation contract, not the current North Star or continuing authorization.

## Goal and vertical slice

> Put the smallest safe whole-vessel operations core into daily use, observe reality, then make only the next minimum change.

`Safety / Operational Truth > Time-to-Real-Use > Feature Completeness`

```text
Booking:   Availability -> Inquiry -> HOLD -> Confirm -> Amend / Cancel
Trip:      Confirmed Booking -> Today's Trips -> Prepare -> Depart -> Return -> Complete
Inventory: BLOCK -> Release
Audit:     cross-cutting
```

The slice succeeds when one authorized Operator completes it without a hidden spreadsheet or API-only side path for the in-scope daily actions.

## One development loop

```text
CORE SAFETY
    | no P0 + exact-head CI + independent review + Owner merge authorization
    v
DEPLOYMENT READINESS
    | reviewed PostgreSQL/config/provisioning/scheduler/backup/health/PII
    v
PILOT CUTOVER
    | real-data scope + reconciliation + rollback + explicit authority switch
    v
REAL USE -> OBSERVED PAIN -> NEXT MINIMUM CHANGE -> CORE SAFETY
```

No WP4/WP5/WP6 is preplanned. Release is a separate optional Gate.

## Core Safety

Core Safety closes only when no operational-truth P0 remains, the exact candidate head passes relevant SQLite and PostgreSQL/concurrency regressions, shared Web/API/job behavior remains consistent, and independent review plus separate Owner merge authorization are recorded.

This path prescribes neither a current implementation nor current authorization. Those live only in `CURRENT_STATE.yaml` and `CURRENT_GATE.md`; immutable review evidence lives in `REVIEW_QUEUE.md`.

## Deployment Readiness

Opens only after Core Safety is merged.

Minimum evidence:

- exact source SHA and PostgreSQL candidate;
- reviewed organization, vessels, windows, slots, compatibility, buffers, HOLD TTL;
- real Operator identities/least privilege;
- idempotent provisioning manifest/command, validation, and rollback;
- HOLD-expiry scheduler, health/errors, audit/outbox visibility;
- backup and actual restore;
- deployment rollback and PII protection;
- physical Demo isolation.

Use reviewed provisioning before building Admin Web. Deployment does not authorize real data.

## Small Pilot Cutover

Requires a separate Owner decision:

- identify current authority and exact cutover time;
- admit only approved fields/records;
- keep the old system for history;
- enter only necessary future active bookings;
- reconcile every admitted Booking/occupied interval;
- prove rollback;
- switch new work at an explicit moment and stop uncontrolled parallel writes.

Full-history automation is not required. BoatOps becomes operational authority only when this Gate completes.

## Real-use feedback

Capture, without PII: workflow step, expected/actual result, impact, evidence, workaround, and whether the cause is universal or organization-specific.

| Class | Rule |
| --- | --- |
| `core-safety` | incorrect operational truth; highest priority |
| `real-use-blocker` | Operator cannot finish daily work |
| `observed-pain` | repeated friction proven by real use |
| `future` | unproven; not scheduled |

Prefer safe SOP/configuration for organization-specific needs. One observation is not proof of a platform feature.

Minimum observability reuses audit, idempotency, revision, database conflicts, outbox, scheduler/health logs, deployment manifest, and backup/rollback receipts. No analytics platform is required.

## Progressive complexity

- Capacity: use SOP if Pilot limits are uniform; otherwise first consider one bounded `max_passengers` constraint.
- Product/Slot: add the smallest mapping only after repeated wrong combinations.
- Admin: consider after repeated, error-prone provisioning.
- Finance/CRM/reporting/maintenance: only for a blocker or repeated pain.
- API/ChannelHub/OTA: only for a real consumer and explicit authority/failure boundary.

## First-Pilot exclusions

- seat/ticket/shared-capacity inventory;
- ChannelHub/OTA/WordPress inventory;
- payment/full accounting/CRM;
- broad Stock/Fuel UI;
- notification/reporting platform;
- maintenance/documents/complex manifest;
- SaaS super-admin;
- automated history migration;
- second-company onboarding;
- public semantic-version Release.

## Gate authority

`CODE / MERGE`, `DEPLOYMENT`, `REAL DATA / CUTOVER`, and `RELEASE` remain separate.

Current state and permission:

- `.project/CURRENT_STATE.yaml`
- `.project/CURRENT_GATE.md`

Evidence/history:

- `.project/REVIEW_QUEUE.md`
- existing closure/release receipts and Git/PR history.
