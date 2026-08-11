# BoatOps Review Queue and Evidence Ledger

Last updated: 2026-08-11 09:35 Asia/Bangkok

This file has two jobs:

1. list active review questions and missing evidence;
2. retain compact, immutable identities for accepted history.

It grants no authorization. Current machine state lives in `CURRENT_STATE.yaml`; allowed and forbidden actions live in `CURRENT_GATE.md`.

## Active review queue

| ID | Status | Review question |
| --- | --- | --- |
| `GOV-DR-001` | `DRAFT / PRIMARY REVIEW REQUIRED` | Does this four-file governance candidate accurately close PR #12 and open Deployment Readiness without authorizing Deployment? |
| `DR-04` | `BLOCKED` | What reviewed, executable, transactional provisioning artifact will create and validate the first Pilot configuration? |
| `DR-16` | `BLOCKED` | Will `main` receive required CI checks and force-push/deletion protection before Deployment closure? |
| `DR-17` | `BLOCKED` | What exact real Pilot organization, Boat, buffer, Slot, compatibility, TTL, timezone, Operator, and service-boundary values are approved? |

All other Deployment Readiness items remain evidence collection or conditional business decisions as recorded below.

## PR #12 Core Safety closure

PR: `#12 Shared Trip Actions and Operator Trip Desk`

```text
PR state: CLOSED / MERGED
candidate head: f3f3a2adee5a76e62f70cc41cef111aa9feb0178
merge base: 1f300c071f9066ff83e102798999e0852cedf7fa
merge commit: 5f1424f189865ca412577510c1ada450e838da18
current canonical main: 5f1424f189865ca412577510c1ada450e838da18
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

## Deployment Readiness evidence queue

| ID | Area | Status | Existing evidence | Missing proof or input |
| --- | --- | --- | --- | --- |
| `DR-01` | Exact source | `PASS` | Canonical main and post-main CI are both pinned to `5f1424f...`; governance worktree started clean at that exact commit | Future deployment manifest must record source/build SHA and config identity |
| `DR-02` | PostgreSQL | `CONDITIONAL` | CI uses PostgreSQL 17, runs migrations, verifies the validated GiST exclusion constraint, and exercises real HTTP concurrency | Real instance, migration/runtime roles, extension permission, TLS mode, UTC setting, and connectivity |
| `DR-03` | Production env | `NOT_PROVEN` | `config/app.php` supports environment-injected `APP_ENV`, `APP_DEBUG`, and `APP_KEY` | Reviewed runtime manifest proving `APP_ENV=production`, `APP_DEBUG=false`, `DB_CONNECTION=pgsql`, and required key presence without values |
| `DR-04` | Provisioning | `BLOCKED` | Schema and domain services can represent required records; Charter specifies a reviewed command/manifest | No executable provisioning command/manifest, validation receipt, idempotency proof, or rollback exists |
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
| WP3 merge commit/current main | `5f1424f189865ca412577510c1ada450e838da18` | CORE SAFETY COMPLETE |
| WP3 post-main CI | Run `31448746777` | OVERALL SUCCESS / BOTH JOBS SUCCESS |

## Interpretation boundaries

- PR #12 and Core Safety are closed history; they are not active blockers.
- The active queue is Deployment Readiness evidence, not WP4/WP5/WP6 feature planning.
- `INV-P0-003` is not reopened.
- Capacity and Product-Slot remain conditional business decisions, not pre-authorized code.
- Historical D1 evidence proves only an isolated fictional SQLite Demo, not real-Pilot PostgreSQL readiness.
- This Draft grants no merge, Deployment, real-data, migration, Cutover, authority-switch, Tag, or Release authorization.
