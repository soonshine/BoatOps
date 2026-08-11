# BoatOps Current Gate

Updated: 2026-08-11 11:42 Asia/Bangkok

## Current decision

```text
REAL_OPERATIONS_DEPLOYMENT_READINESS
CORE_SAFETY_COMPLETE
DEPLOYMENT_READINESS_ASSESSMENT_OPEN
MINIMUM_CLOSURE_PLAN_DEFINED_NOT_AUTHORIZED
DEPLOYMENT_NOT_AUTHORIZED
```

PR #15 is merged and the canonical phase is `REAL_OPERATIONS_DEPLOYMENT_READINESS`. The current four-file closure-plan candidate only corrects post-merge bookkeeping and defines the shortest future evidence sequence. It remains a Draft until separately reviewed and merged; it does not close any DR item or grant authority over code implementation, repository settings, production, real data, Cutover, Tag, or Release.

The exact machine state is `CURRENT_STATE.yaml`. Review identities and the Deployment Readiness evidence queue are in `REVIEW_QUEUE.md`.

`merge != deploy != cutover != release`

## Canonical source identity

Live canonical source identity is resolved from GitHub `refs/heads/main` at every review, merge, Deployment, Cutover, and Release Gate.

The verified governance baseline for PR #16 is `1864469b1b159442ecc598c919faa75431dca778`. This is immutable evidence of the `main` state against which PR #16 was authored and reviewed. It is not a prediction or promise of the commit that would result from merging PR #16.

```text
LIVE_BRANCH_REF_IS_EXTERNAL_STATE
STORED_SHA = HISTORICAL_FACT | REVIEWED_CANDIDATE | VERIFIED_BASELINE
FUTURE_SELF_MERGE_SHA_EMBEDDING = FORBIDDEN
```

## Core Safety closure

PR #12 is merged and closed.

- candidate head: `f3f3a2adee5a76e62f70cc41cef111aa9feb0178`;
- PR #12 merge commit/resulting main: `5f1424f189865ca412577510c1ada450e838da18`;
- exact candidate CI Run `31374570259`: both jobs SUCCESS;
- Primary cross-invariant review: PASS;
- Codex narrow counter-audit: PASS;
- post-main CI Run `31448746777`: overall SUCCESS; both jobs SUCCESS.

PR #15 governance closure is also merged and closed.

- reviewed head: `65bbb8b03d370332b8afd35f71dcc64b6cdab02d`;
- historical PR #15 merge commit: `1864469b1b159442ecc598c919faa75431dca778`;
- PR #16 verified authoring/review baseline: `1864469b1b159442ecc598c919faa75431dca778`;
- merge parents: `5f1424f189865ca412577510c1ada450e838da18` and `65bbb8b03d370332b8afd35f71dcc64b6cdab02d`;
- exact-head CI Run `31453362814`: both jobs SUCCESS;
- Primary Review: PASS;
- post-main CI Run `31454471881`: overall SUCCESS; both jobs SUCCESS.

Current invariant disposition:

```text
INV-P0-001 CLOSED / MERGED
INV-P0-002 CLOSED / MERGED
INV-P0-004 MERGED BOUNDED HARDENING
NO OPEN CORE SAFETY P0
```

`INV-P0-003` remains `DEFENSE_IN_DEPTH / NOT_CURRENT_CORE_BLOCKER`. `REALUSE-P1-001` and `REALUSE-P1-002` remain deferred under their accepted evidence thresholds.

## Deployment Readiness question

> Can one controlled Pilot environment run the already-merged whole-vessel workflow safely?

Current classification:

`DEPLOYMENT_READINESS_NOT_YET_PROVEN`

Source capability is materially suitable, but required production runtime, infrastructure, provisioning, governance, and real business-configuration evidence is incomplete.

This Gate is evidence collection and minimum deployment-only closure. It is not a feature-development package.

## Minimum closure streams

| Stream | Bucket | Current state | Boundary |
| --- | --- | --- | --- |
| `DR-04 Provisioning` | `BOUNDED_CODE_ARTIFACT` | Design complete; implementation absent | Separate Owner authorization required before code |
| `DR-16 Main protection` | `REPOSITORY_PLATFORM_MUTATION` | `main.protected=false`; rulesets `0`; Issue #4 OPEN | Settings mutation not authorized |
| `DR-17 Pilot configuration` | `OWNER_OPERATOR_INPUT` | Real values not supplied | No customer records; no invented defaults |
| `TARGET_RUNTIME_PROOF` | `TARGET_RUNTIME_PROOF` | Hosting target required; proof not authorized | One coherent synthetic package after target selection |

The DR-04 minimum design is one `pilot:provision {manifest} {--validate}` Artisan command, one versioned non-secret manifest parser/DTO, one transactional application service, focused tests, and at most one fictional example manifest. It requires no schema migration. Exact-match re-runs succeed unchanged; any existing-value drift fails closed without partial writes. Secrets remain outside the manifest.

The target-runtime package must verify exact SHA, PostgreSQL, production env, Demo disabled, secret injection, HTTPS/restricted access, Operator login, scheduler, health, logs, PII controls, backup, restore, abort/rollback, and the synthetic Login-to-Audit smoke sequence. Hosting selection is a prerequisite; customer data is forbidden.

## Allowed now

- read-only Deployment Readiness investigation;
- infrastructure and configuration design;
- collection and review of real Pilot business inputs without entering customer records;
- design of one bounded, transactional provisioning manifest/command;
- synthetic smoke-test and restore-test planning;
- governance synchronization and review.

These allowed activities do not authorize implementation unless a later Owner decision names an exact bounded artifact.

## Current blockers and missing proof

### Concrete blockers

1. `DR-04`: no reviewed executable provisioning command/manifest currently creates Organization, Boat/buffers, Trip Template, Slot applicability/compatibility, HOLD TTL, Operator User, and membership as one bounded transaction with validation and rollback.
2. `DR-16`: live GitHub branch metadata reports `main.protected = false`; repository rulesets count is zero; Issue #4 remains open.
3. `DR-17`: real Pilot organization, vessel, buffer, slot, compatibility, TTL, timezone, operator, and service-boundary values have not been supplied or approved.

### Runtime proof not yet available

- production PostgreSQL target, migration/runtime roles, TLS mode, timezone, and connection proof;
- production environment manifest with `APP_ENV=production`, `APP_DEBUG=false`, Demo disabled, and non-secret presence checks;
- runtime-injected APP_KEY, DB credentials, Operator credentials, and any required API credentials with rotation ownership;
- authenticated real Operator account/membership and least-privilege runtime proof;
- HTTPS plus private or appropriately restricted ingress and trusted-proxy/header proof;
- scheduler process, one-minute HOLD expiry execution, overlap prevention, and failure visibility;
- health, DB-connectivity, 500/DB/scheduler error visibility, and log retention/access proof;
- PostgreSQL backup schedule/retention and an actual synthetic restore test;
- deployment abort/rollback receipt and bounded synthetic Pilot smoke receipt.

## Deployment Readiness constraints

- first Pilot remains one organization and preferably one Boat or a very small fleet;
- PostgreSQL is mandatory; SQLite is forbidden for a real Pilot;
- Operator Web is primary; manual Booking entry is sufficient;
- no automated sales channels or external outbox consumer are required for the first Pilot;
- external payment/refund handling remains outside BoatOps;
- weather/closure uses BLOCK;
- historical migration is not required;
- only approved future active bookings may later be manually entered or admitted through a separately authorized controlled import;
- after an explicit Cutover there must be no uncontrolled dual write.

## Conditional business decisions

### Passenger capacity

`CONDITIONAL / BUSINESS_INPUT_REQUIRED`

If every Pilot Booking is operationally capped at or below the safe minimum Boat capacity, use reviewed SOP/configuration and add no code. Only a proven heterogeneous-capacity overbooking risk can justify later consideration of one bounded `boats.max_passengers` guard. Seat inventory remains out of scope.

### Product to Slot

`CONDITIONAL / BUSINESS_INPUT_REQUIRED`

Use reviewed configuration/SOP if the Operator can reliably choose valid Slots. A mapping/filter is considered only after repeated real error evidence. No Product engine is authorized.

## Minimum future execution order

1. Merge this bookkeeping correction only after Primary Review.
2. In parallel: collect DR-17 inputs, prepare the DR-16 mutation, retain the reviewed DR-04 design, and select a hosting/runtime target.
3. After DR-17 manifest shape is approved, separately authorize and implement the bounded DR-04 artifact.
4. Separately authorize and execute the minimum DR-16 repository protection mutation.
5. Against the selected target, separately authorize one synthetic target-runtime proof package.
6. Run the bounded synthetic Pilot smoke only after the candidate runtime exists.
7. Perform a final Deployment Readiness review.
8. Ask the Owner for a separate Deployment decision; do not ask for Cutover yet.

## Owner decisions required next

1. Provide and approve the blank DR-17 Pilot configuration inputs.
2. Authorize the minimum DR-16 `main` protection mutation.
3. Authorize the bounded DR-04 provisioning implementation after the manifest shape is approved.
4. Select or confirm one hosting/runtime target.
5. Later authorize synthetic target-runtime proof against that target.

## Four Gate status

| Gate | Status | Authorization |
| --- | --- | --- |
| CODE / MERGE | `CORE_SAFETY_COMPLETE` | No application code or feature package authorized; this closure-plan Draft is not authorized to merge |
| DEPLOYMENT | `READINESS_ASSESSMENT_OPEN` | `DEPLOYMENT_AUTHORIZED = false` |
| REAL DATA / CUTOVER | `NOT_OPEN` | Real data, migration, Cutover, and authority switch all false |
| RELEASE | `NOT_OPEN` | Tag and GitHub Release false |

## Explicitly not authorized

- WP4, WP5, WP6, or another feature package;
- CRM, Finance expansion, Admin UI, Capacity engine, Product engine, ChannelHub, OTA, or SPA rewrite;
- production deploy or production enablement;
- live database provisioning;
- real customer or future Booking entry;
- historical migration, reconciliation, or dual-write operation;
- Cutover or authority switch;
- Tag or GitHub Release.

Passing tests, CI, a Draft PR, or a readiness assessment never advances another Gate.

## Next decision

This closure-plan Draft must stop for:

`PRIMARY_REVIEW_MINIMUM_DEPLOYMENT_READINESS_CLOSURE_PLAN`

Only after Primary Review may the Owner separately decide the five bounded actions above. DR-04 implementation, DR-16 mutation, runtime proof, actual Deployment, and every later Gate remain unauthorized.
