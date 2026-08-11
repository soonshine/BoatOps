# BoatOps Review Queue and Evidence Ledger

Last updated: 2026-08-11 10:56 Asia/Bangkok

This file has two jobs:

1. list active review questions and missing evidence;
2. retain compact, immutable identities for accepted history.

It grants no authorization. Current machine state lives in `CURRENT_STATE.yaml`; allowed and forbidden actions live in `CURRENT_GATE.md`.

## Active review queue

| ID | Status | Review question |
| --- | --- | --- |
| `GOV-DR-CLOSURE-001` | `DRAFT / PRIMARY REVIEW REQUIRED` | Does this four-file candidate correctly reconcile PR #15 and define the minimum closure sequence without authorizing any mutation? |
| `DR-04` | `BLOCKED / DESIGN_COMPLETE / IMPLEMENTATION_NOT_AUTHORIZED` | Will a later bounded PR implement only the reviewed transactional provisioning command and manifest? |
| `DR-16` | `BLOCKED / MUTATION_NOT_AUTHORIZED` | Will `main` receive the minimum required CI checks and force-push/deletion protection? |
| `DR-17` | `BLOCKED / OWNER_INPUT_REQUIRED` | What exact real Pilot organization, Boat, buffer, Slot, compatibility, TTL, timezone, Operator, and service-boundary values are approved? |
| `TARGET-RUNTIME-PROOF` | `TARGET_REQUIRED / NOT_AUTHORIZED` | Which single hosting target will receive the synthetic PostgreSQL, env, TLS, scheduler, logging, backup/restore, abort, and smoke proof package? |

All other Deployment Readiness items remain evidence collection or conditional business decisions as recorded below.

## PR #12 Core Safety closure

PR: `#12 Shared Trip Actions and Operator Trip Desk`

```text
PR state: CLOSED / MERGED
candidate head: f3f3a2adee5a76e62f70cc41cef111aa9feb0178
merge base: 1f300c071f9066ff83e102798999e0852cedf7fa
PR #12 merge commit/resulting main: 5f1424f189865ca412577510c1ada450e838da18
current canonical main after PR #15: 1864469b1b159442ecc598c919faa75431dca778
```

Acceptance evidence:

- Primary cross-invariant review: `PASS`;
- Codex narrow counter-audit: `CORE_SAFETY_REPAIR_COUNTER_AUDIT_PASS`;
- exact candidate CI Run `31374570259`: `Quality and contracts` SUCCESS; `PostgreSQL concurrency` SUCCESS;
- post-main CI Run `31448746777`: overall SUCCESS; `Quality and contracts` SUCCESS; `PostgreSQL concurrency` SUCCESS.

Accepted disposition:

| Finding | Final disposition |
| --- | --- |
| `INV-P0-001` | `CLOSED / MERGED / UNIVERSAL CORE INVARIANT` |
| `INV-P0-002` | `CLOSED / MERGED / UNIVERSAL CORE INVARIANT` |
| `INV-P0-003` | `DEFENSE IN DEPTH / NOT CURRENT CORE BLOCKER` |
| `INV-P0-004` | `MERGED BOUNDED HARDENING` |
| `REALUSE-P1-001` | `DEFER / OBSERVED PAIN REQUIRED` |
| `REALUSE-P1-002` | `DEFER / REAL COMPLIANCE OR AUDIT EVIDENCE REQUIRED` |

There is no open Core Safety P0.

## PR #15 Deployment Readiness governance closure

```text
PR state: CLOSED / MERGED
reviewed head: 65bbb8b03d370332b8afd35f71dcc64b6cdab02d
merge base: 5f1424f189865ca412577510c1ada450e838da18
merge commit/current canonical main: 1864469b1b159442ecc598c919faa75431dca778
merge parents:
  5f1424f189865ca412577510c1ada450e838da18
  65bbb8b03d370332b8afd35f71dcc64b6cdab02d
```

Acceptance evidence:

- Primary Review: `GOVERNANCE_POST_PR12_DEPLOYMENT_READINESS_PRIMARY_REVIEW_PASS`;
- exact-head CI Run `31453362814`: both jobs SUCCESS;
- post-main CI Run `31454471881`: overall SUCCESS; `Quality and contracts` SUCCESS; `PostgreSQL concurrency` SUCCESS.

The canonical phase is `REAL_OPERATIONS_DEPLOYMENT_READINESS`; classification remains `DEPLOYMENT_READINESS_NOT_YET_PROVEN`.

## Deployment Readiness evidence queue

| ID | Area | Status | Existing evidence | Missing proof or input |
| --- | --- | --- | --- | --- |
| `DR-01` | Exact source | `PASS` | Canonical main `1864469b...` and post-main CI Run `31454471881` are exact and successful | Future deployment manifest must record source/build SHA and config identity |
| `DR-02` | PostgreSQL | `CONDITIONAL` | CI uses PostgreSQL 17, runs migrations, verifies the validated GiST exclusion constraint, and exercises real HTTP concurrency | Real instance, migration/runtime roles, extension permission, TLS mode, UTC setting, and connectivity |
| `DR-03` | Production env | `NOT_PROVEN` | `config/app.php` supports environment-injected `APP_ENV`, `APP_DEBUG`, and `APP_KEY` | Reviewed runtime manifest proving `APP_ENV=production`, `APP_DEBUG=false`, `DB_CONNECTION=pgsql`, and required key presence without values |
| `DR-04` | Provisioning | `BLOCKED` | Read-only design confirms existing schema/services support a small command with no migration; minimum design is recorded below | Implementation, tests, example manifest, exact-head CI, and execution receipt do not exist and are not authorized |
| `DR-05` | Demo isolation | `PASS` | `DemoSiteSeeder` rejects production unless explicit public-read-only, isolated SQLite Demo gates all pass; real PostgreSQL cannot satisfy that path; Demo token is explicit | Production manifest must retain all Demo flags disabled and must not invoke `db:seed` |
| `DR-06` | Operator access | `NOT_PROVEN` | Session login, hashed passwords, active one-organization membership, three least-privilege flags, rate limiting, isolation, and fail-closed tests exist | Real Operator user/membership, credential delivery/rotation, revocation owner, and runtime login proof |
| `DR-07` | TLS/private access | `NOT_PROVEN` | Repository has HTTPS/nginx examples and historical loopback-only fictional Operator evidence | Owner-selected hosting target, current certificate, private/restricted access rule, trusted-proxy headers, and external verification |
| `DR-08` | Secrets | `NOT_PROVEN` | `.env` and common key files are ignored; application consumes secrets through runtime environment and stores password/API-token hashes | Secret store/injection, presence checks, access owner, backup boundary, and rotation procedure |
| `DR-09` | Scheduler | `NOT_PROVEN` | `holds:expire` exists; scheduler runs every minute with `withoutOverlapping`; a cron example exists | Deployed scheduler process, correct user/path/env, repeated execution proof, lock behavior, and failure visibility |
| `DR-10` | Health | `CONDITIONAL` | Laravel `/up` route is registered and proves the application can boot/respond | Candidate runtime response plus separate PostgreSQL connectivity/readiness proof; no application DB health listener exists |
| `DR-11` | Logs/errors | `NOT_PROVEN` | Laravel single/daily/stderr/syslog channels exist; nginx and scheduler examples name log paths | Chosen channels, permissions, retention, 500/DB/scheduler failure capture, review owner, and outbox/backlog check |
| `DR-12` | PII safety | `CONDITIONAL` | Generic API errors, organization isolation, Calendar no-PII tests, list-view minimization, escaped Audit output, and PII-redacted dossier audit metadata exist | Production debug=false, restricted log access, retention/backups, and synthetic runtime disclosure checks |
| `DR-13` | Backup | `NOT_PROVEN` | Historical Demo receipts contain SQLite backup evidence only | PostgreSQL backup mechanism, schedule, encryption/access, retention, monitoring, and ownership |
| `DR-14` | Restore | `NOT_PROVEN` | Historical fictional SQLite restore is not proof for the Pilot PostgreSQL target | Documented PostgreSQL restore procedure and successful synthetic restore receipt |
| `DR-15` | Outbox | `NOT_REQUIRED_FOR_PILOT` | Transactional outbox is durable; first Pilot has no external integration consumer requirement | Confirm bounded Pilot volume and define row-count/backlog visibility plus later retention decision; do not build ChannelHub |
| `DR-16` | Branch protection | `BLOCKED` | Live branch API reports `main.protected=false`; repository rulesets count is zero; Issue #4 is open | Require both CI jobs, PR-before-merge, block force push, and block deletion |
| `DR-17` | Business config | `BLOCKED` | Code represents organization, Boat/buffers, templates, Slots, applicability, compatibility, TTL, users, and memberships | All real first-Pilot values and operating/service boundaries require Owner/operator approval |
| `DR-18` | Capacity conditional | `CONDITIONAL` | `party_size` is recorded; whole-vessel inventory is authoritative; no capacity engine exists | Boat safe capacities and maximum accepted party rule; choose SOP/config unless heterogeneous risk proves one bounded guard necessary |
| `DR-19` | Product-Slot conditional | `CONDITIONAL` | Operator chooses Product and Slot independently; compatibility and applicability exist; no Product-Slot engine exists | Approved combinations and evidence that SOP/config is reliable; code only after repeated real error evidence |
| `DR-20` | Cutover model | `CONDITIONAL` | Product path supports keeping history in the old system, entering only future active bookings, explicit Cutover, and no uncontrolled dual write | Named current authority, admitted fields/records, reconciliation owner, exact Cutover moment, and operator acceptance |
| `DR-21` | Abort/rollback | `NOT_PROVEN` | BoatOps is explicitly not authority before Cutover; historical Demo rollback evidence exists | Target-specific deploy abort, test-database discard, source/config rollback, and PostgreSQL recovery receipt |
| `DR-22` | Smoke test | `NOT_PROVEN` | Complete Web workflow and synthetic automated tests exist | Authorized candidate runtime and bounded synthetic Login-to-Audit smoke receipt; never use real production data under this Gate |

## Closure buckets

Each DR item belongs to exactly one closure bucket.

| DR item | Bucket | Future action | Owner authorization required? |
| --- | --- | --- | --- |
| `DR-01` | `TARGET_RUNTIME_PROOF` | Pin deployed source/build/config identity | Yes, as part of runtime proof |
| `DR-02` | `TARGET_RUNTIME_PROOF` | Prove target PostgreSQL, roles, extension, TLS, UTC, migrations and connectivity | Yes |
| `DR-03` | `TARGET_RUNTIME_PROOF` | Prove redacted production env and required secret presence | Yes |
| `DR-04` | `BOUNDED_CODE_ARTIFACT` | Implement the reviewed provisioning command only | Yes |
| `DR-05` | `TARGET_RUNTIME_PROOF` | Prove Demo disabled and no Demo seeding | Yes, as part of runtime proof |
| `DR-06` | `TARGET_RUNTIME_PROOF` | Prove synthetic Operator account, membership and least privilege | Yes |
| `DR-07` | `TARGET_RUNTIME_PROOF` | Prove HTTPS and restricted/private access | Yes |
| `DR-08` | `TARGET_RUNTIME_PROOF` | Prove secret injection, ownership and rotation | Yes |
| `DR-09` | `TARGET_RUNTIME_PROOF` | Prove recurring `holds:expire` execution and failure visibility | Yes |
| `DR-10` | `TARGET_RUNTIME_PROOF` | Prove application response and PostgreSQL readiness | Yes |
| `DR-11` | `TARGET_RUNTIME_PROOF` | Prove minimum error/log capture, retention and owner | Yes |
| `DR-12` | `TARGET_RUNTIME_PROOF` | Prove runtime debug/log/PII controls with synthetic data | Yes |
| `DR-13` | `TARGET_RUNTIME_PROOF` | Configure and prove PostgreSQL backup | Yes |
| `DR-14` | `TARGET_RUNTIME_PROOF` | Restore a synthetic PostgreSQL backup to a clean target | Yes |
| `DR-15` | `NOT_REQUIRED_FOR_FIRST_PILOT` | Confirm bounded outbox growth; add no consumer | No code; review only |
| `DR-16` | `REPOSITORY_PLATFORM_MUTATION` | Apply minimum `main` protection and return settings receipt | Yes |
| `DR-17` | `OWNER_OPERATOR_INPUT` | Complete and approve the Pilot configuration input sheet | Owner decision/input |
| `DR-18` | `CONDITIONAL_NO_CODE_DEFAULT` | Use safe party-size SOP/config unless real heterogeneous risk is proven | Only if later code is proposed |
| `DR-19` | `CONDITIONAL_NO_CODE_DEFAULT` | Use an approved Product-to-Slot combination table/SOP | Only if later code is proposed |
| `DR-20` | `OWNER_OPERATOR_INPUT` | Name current authority, admitted future records, owner and proposed Cutover policy | Owner decision/input; no Cutover now |
| `DR-21` | `TARGET_RUNTIME_PROOF` | Prove target-specific abort, discard, rollback and recovery | Yes |
| `DR-22` | `TARGET_RUNTIME_PROOF` | Run bounded synthetic Login-to-Audit smoke | Yes |

## DR-04 minimum design

Proposed future command:

`php artisan pilot:provision <manifest.json> --validate`

The versioned, non-secret `v1` manifest contains only Organization/timezone, Boats/buffers, Trip Templates, reusable Slot Offerings/applicable Boats, same-service-date compatibility, HOLD TTL, Operator identity/membership/permissions, and service-boundary/SOP metadata used for validation receipts.

Acceptance rules:

- reject duplicate identities, unknown Boat/Slot references, invalid or mismatched times/durations, cross-midnight Slots, invalid compatibility pairs/policies, invalid HOLD TTL, missing active membership/permissions, and any enabled Demo flag;
- validate all references before writing and wrap all writes in one outer database transaction; any failure leaves zero partial Pilot configuration;
- an exact existing configuration returns `UNCHANGED`; any identity/value drift fails `CONFIGURATION_DRIFT`; never silently overwrite;
- passwords, APP_KEY, DB credentials, API tokens, and other secrets are forbidden in the manifest and receipt; initialize the Operator password through a separately injected secret or hidden controlled reset;
- reuse `SlotCatalogService`, `SlotCompatibilityService`, `OrganizationHoldTtlPolicy::KEY`, hashed `User` credentials, and existing membership columns instead of creating parallel domain rules.

Estimated future code surface:

1. `app/Console/Commands/ProvisionPilot.php`;
2. one small versioned manifest DTO/parser;
3. one transactional `ProvisionPilot` application service;
4. focused command/service tests;
5. at most one fictional example JSON manifest.

No schema migration, Admin UI, setup wizard, generic sync engine, SaaS onboarding, CRM, Finance, capacity engine, Product engine, OTA, or ChannelHub is required.

`DR04_IMPLEMENTATION_AUTHORIZED = false`

## DR-17 blank Pilot Configuration Input Sheet

Owner/operator must complete and approve this structure without customer records or invented defaults:

```yaml
pilot_organization: null
operating_timezone: null

boats:
  - name: null
    buffer_before_minutes: null
    buffer_after_minutes: null
    safe_max_party_size_or_sop_limit: null

trip_templates:
  - code: null
    name: null

slots:
  - identity: null
    name: null
    service_start: null
    service_end: null
    applicable_boats: []

compatibility:
  - slot_a: null
    slot_b: null
    policy: null # ALLOW or DENY

hold_ttl_minutes: null

operator:
  name_or_email_identifier: null
  organization_membership: null
  required_permissions:
    can_calendar_read: null
    can_booking_workflow: null
    can_block: null

pilot_service_boundary:
  included: []
  excluded: []

product_to_slot_sop:
  - product: null
    approved_slots: []

current_inventory_authority: null

future_active_booking_cutover_policy:
  admitted_records: null
  reconciliation_owner: null
  proposed_cutover_moment: null
  old_authority_until_explicit_cutover: null
  no_uncontrolled_dual_write: null
```

Capacity defaults to SOP/config and no code. Product-to-Slot defaults to the reviewed combination table above and no code. Cutover remains a later, separately authorized Gate.

## DR-16 future mutation receipt

Live verification on 2026-08-11:

```text
main.protected = false
repository rulesets = 0
Issue #4 = OPEN
```

A separately authorized mutation must prove:

```text
main.protected = true
pull request before merge = required
required checks:
  Quality and contracts
  PostgreSQL concurrency
force push = blocked
deletion = blocked
```

Signed commits, deployment environments, and multiple mandatory approvers are optional and not part of this minimum mutation.

`DR16_MUTATION_AUTHORIZED = false`

## Target runtime proof package

One Owner-selected hosting/runtime target is required before this package can close. Keep it as one coherent synthetic task covering exact Git SHA, PostgreSQL/extension/roles/TLS/UTC/migrations, production env, Demo disabled, secrets, restricted HTTPS Operator access, scheduler, health/DB readiness, logs/errors/PII controls, backup, clean-target restore, abort/rollback, and the synthetic smoke sequence below.

CI PostgreSQL is supporting evidence only. Backup configured and restore tested remain separate receipts inside the same package. No customer data, historical migration, authority switch, or Cutover is permitted.

## Minimum synthetic Pilot smoke sequence

Define for a later, explicitly authorized candidate runtime:

```text
Login
-> Calendar
-> Inquiry
-> HOLD
-> Confirm
-> Booking Workbench
-> Prepare
-> Depart
-> Return
-> Complete at or after occupied_end
-> BLOCK
-> Audit verification
```

This sequence is not authorized to run against production or real data by this governance candidate.

## Canonical evidence ledger

| Identity | Commit / run / artifact | Recorded status |
| --- | --- | --- |
| G1 reviewed code | `20978a169bbd52278b3bc4ab36e901a55c7e0b00` | COMPLETE / FROZEN |
| D1 product source | `f9503b598b174b7a6891fcde0d984514a3cd0fcd` | COMPLETE / FICTIONAL DEMO ONLY |
| D1 closure | `.project/D1_GOVERNANCE_CLOSURE.md` | COMPLETE / EVIDENCE CLOSED |
| Pilot scope contract | `docs/product/REAL_OPERATIONS_PILOT_MVP_SCOPE_FREEZE.md` | FROZEN WP1-WP3 HISTORY / NOT CURRENT NORTH STAR |
| WP1 PR / reviewed head | `#8` / `973e0456bf3c8672ae4ba03c61ac0a1c88cfd416` | MERGED / PRIMARY REVIEW PASS |
| WP1 exact-head / post-main CI | Runs `31310148095` / `31310579582` | SUCCESS / SUCCESS |
| WP1 resulting main | `1114307d358e67d91ebcf742a26e9d7469209e67` | COMPLETE |
| WP2 PR / reviewed head | `#10` / `b340e7c84480c6bcc92ae62829cad0f7f0661fec` | MERGED / PRIMARY REVIEW PASS |
| WP2 exact-head / post-main CI | Runs `31317044622` / `31346016491` | SUCCESS / SUCCESS |
| WP2 resulting main | `763d22bfc4ddaf0a84df1188d50f6d40b2fa72fc` | COMPLETE |
| Project Reset PR / head | `#13` / `aede9a495b1a6f98a218fd0d26d944b469f86980` | MERGED / GOVERNANCE-ONLY RESET |
| Project Reset resulting main | `32f817c4618d522b6d73253b3f1dcdc12018a78f` | HISTORICAL CANONICAL IDENTITY |
| Project Reset post-main CI | Run `31360041676` | BOTH JOBS SUCCESS |
| Core Safety reconciliation PR / head | `#14` / `7df4ff57fdba09955f8164e9f4a99b02ef91da5e` | MERGED / GOVERNANCE-ONLY |
| Core Safety reconciliation resulting main | `1f300c071f9066ff83e102798999e0852cedf7fa` | HISTORICAL PRE-PR12 MAIN |
| Project-wide counter-audit | `CODEX_BOATOPS_PROJECT_WIDE_PRE_REAL_USE_AUDIT` | READ_ONLY / PRIMARY RECONCILED |
| WP3 / Core Safety PR | `#12` | MERGED / CLOSED |
| WP3 initial reviewed head | `d841418c24c90c30ceeb203e17150e55cb46d538` | HISTORICAL PRIMARY REVIEW PASS / SUPERSEDED |
| WP3 repaired candidate head | `f3f3a2adee5a76e62f70cc41cef111aa9feb0178` | PRIMARY CROSS-INVARIANT PASS / CODEX COUNTER-AUDIT PASS |
| WP3 repaired exact-head CI | Run `31374570259` | BOTH JOBS SUCCESS |
| WP3 merge commit/resulting main | `5f1424f189865ca412577510c1ada450e838da18` | CORE SAFETY COMPLETE |
| WP3 post-main CI | Run `31448746777` | OVERALL SUCCESS / BOTH JOBS SUCCESS |
| Deployment Readiness governance PR / head | `#15` / `65bbb8b03d370332b8afd35f71dcc64b6cdab02d` | MERGED / PRIMARY REVIEW PASS |
| PR #15 exact-head CI | Run `31453362814` | BOTH JOBS SUCCESS |
| PR #15 merge commit/current main | `1864469b1b159442ecc598c919faa75431dca778` | CANONICAL DEPLOYMENT READINESS PHASE |
| PR #15 post-main CI | Run `31454471881` | OVERALL SUCCESS / BOTH JOBS SUCCESS |

## Interpretation boundaries

- PR #12 and Core Safety are closed history; they are not active blockers.
- The active queue is Deployment Readiness evidence, not WP4/WP5/WP6 feature planning.
- `INV-P0-003` is not reopened.
- Capacity and Product-Slot remain conditional business decisions, not pre-authorized code.
- Historical D1 evidence proves only an isolated fictional SQLite Demo, not real-Pilot PostgreSQL readiness.
- PR #15 is merged history; this new closure-plan Draft grants no merge, DR-04 implementation, DR-16 mutation, Deployment, real-data, migration, Cutover, authority-switch, Tag, or Release authorization.
