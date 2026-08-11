# BoatOps Current Gate

Updated: 2026-08-11 09:35 Asia/Bangkok

## Current decision

```text
REAL_OPERATIONS_DEPLOYMENT_READINESS
CORE_SAFETY_COMPLETE
DEPLOYMENT_READINESS_ASSESSMENT_OPEN
DEPLOYMENT_NOT_AUTHORIZED
```

This governance synchronization is a Draft candidate until separately reviewed and merged to canonical `main`. It records the transition from merged Core Safety to evidence-based Deployment Readiness; it does not deploy anything and grants no authority over production, real data, Cutover, Tag, or Release.

The exact machine state is `CURRENT_STATE.yaml`. Review identities and the Deployment Readiness evidence queue are in `REVIEW_QUEUE.md`.

`merge != deploy != cutover != release`

## Core Safety closure

PR #12 is merged and closed.

- candidate head: `f3f3a2adee5a76e62f70cc41cef111aa9feb0178`;
- merge commit/current canonical main: `5f1424f189865ca412577510c1ada450e838da18`;
- exact candidate CI Run `31374570259`: both jobs SUCCESS;
- Primary cross-invariant review: PASS;
- Codex narrow counter-audit: PASS;
- post-main CI Run `31448746777`: overall SUCCESS; both jobs SUCCESS.

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

## Four Gate status

| Gate | Status | Authorization |
| --- | --- | --- |
| CODE / MERGE | `CORE_SAFETY_COMPLETE` | No new application code or feature package authorized; this governance Draft is not authorized to merge |
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

This Draft must stop for:

`PRIMARY_REVIEW_POST_PR12_GOVERNANCE_AND_DEPLOYMENT_READINESS`

Only after Primary Review may the Owner consider a new, exact authorization for the smallest Deployment Readiness closure artifacts. Actual Deployment remains a separate later decision.
