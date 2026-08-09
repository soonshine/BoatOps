# BoatOps G1 Final Governance Closure

Closure timestamp: 2026-08-09 10:31 Asia/Bangkok

State: G1_APPROVED_PENDING_OWNER_MERGE_AUTHORIZATION

This document is governance/evidence only. The reviewed business-code head is
immutable. The commit containing this file must be a direct child of
20978a169bbd52278b3bc4ab36e901a55c7e0b00 and may change only .project/**.

## 1. Frozen G1 record

- Base: 3826cb2c29aea4d2b92a90e04c14f8c218fbf45c
- Reviewed code head: 20978a169bbd52278b3bc4ab36e901a55c7e0b00
- Branch: codex/boatops-g1-operator-mvp
- Range size: 12 commits / 55 changed files
- P0: 0
- P1: 0
- Exact reviewed-code CI:
  https://github.com/soonshine/BoatOps/actions/runs/31291676080
- Quality and contracts job 93189841642: success
- PostgreSQL concurrency job 93189841734: success

Independent local evidence at the reviewed code head:

- full PHPUnit: 245 tests / 2,571 assertions;
- G0 suite: 130 tests / 1,482 assertions;
- G1 suite: 115 tests / 1,089 assertions;
- Pint, API/event contracts, production build, Composer validation/audit,
  npm audit, diff check, and SQLite migration round trip: passed;
- dependency findings: Composer 0 advisories; npm 0 vulnerabilities.

Authoritative inventory mutations from API, Operator UI, and expiry jobs reuse
the same Application/domain actions. No parallel inventory-rule path was found.

## 2. Exact G1 commit sequence

Starting after the frozen base:

1. 5394373992cd7e55bc0d1abbaafb2090c8ec16f0 - add G1 operator access and inquiry foundation
2. 9ce52fc630df632bd06d1e1944cbe4c4be1cffb4 - harden operator session and inquiry idempotency
3. 9e40fd2354c8218109ee63d01ff1b88eaaff4b3b - share hold application actions
4. fb587c5b0671f31e5a222836225a43e06fe4ea8d - drain due holds and enforce expiry invariant
5. 142cbb8d177aa0b4c82f4b4bf32400051b09f410 - share booking application actions
6. ad6367b8784519cea801370aca38402d00ff9ce2 - share operational block actions
7. 0d3331bb24992253eefb7fb0c7a3ae0d7447a768 - add operator inquiry/HOLD workflow
8. 5e489055e79e296d93923c82b5a26d30212abd02 - add operator booking workflow
9. f198b56e24ff84437440358086b4cc17c92cce1c - add block management UI
10. c89de374be80643fa5fb15251c2ddae52ff30755 - add read-only audit trail
11. d51abe4fb3327f62046503603e13184e6b2e0b89 - enforce inventory state integrity
12. 20978a169bbd52278b3bc4ab36e901a55c7e0b00 - reject partial-null inventory intervals

Parent proof:

- parent(d51abe4) = c89de374be80643fa5fb15251c2ddae52ff30755
- parent(20978a1) = d51abe4fb3327f62046503603e13184e6b2e0b89

## 3. Reconciled remediation chronology

Cycle 1:

- Hermes session 20260809_085618_87a357 made four uncommitted, out-of-scope
  inquiry/Demo changes.
- The reviewer rejected them.
- Cleanup session 20260809_091133_ea0184 restored exactly those four files.
- Final state: clean c89de374be80643fa5fb15251c2ddae52ff30755;
  commits: 0.

Cycle 2:

- Hermes session 20260809_091327_f3552e stopped because the checked-in
  governance still said STOP_BEFORE_G1.
- It explicitly reported BLOCKED BY CURRENT GATE - no code changed.
- Final state: clean c89de374be80643fa5fb15251c2ddae52ff30755;
  commits: 0.

Cycle 3:

- Session 20260809_091653_6605ee initialized the final authorized cycle but
  received incomplete findings and made no changes.
- Continuation session 20260809_092202_07c567 contained the complete bounded
  findings. Its prompt called itself actual implementation cycle 2/3. That
  label was chronologically wrong because formal Cycle 2 had already ended.
- Final continuation session 20260809_095859_256d5d handled the independent
  null-safety re-review in the same shared worktree.
- The exact Git results were:
  c89de374 -> d51abe4 at 2026-08-09 09:59:15 +07:00, then
  d51abe4 -> 20978a1 at 2026-08-09 10:04:25 +07:00.
- Both commits therefore belong to governance Cycle 3. The multiple Hermes
  sessions were delivery continuations, not additional remediation cycles.

No Cycle 4 occurred. The previous final summary's attribution of substantive
fixes to Cycle 2 is superseded by this SHA- and session-backed chronology.

## 4. D0.1 actual execution decision

D0.1 = PASS / DEPLOYMENT_ACCEPTED

This is not reconstructed from a missing deployment. The evidence exists in
three independent layers:

1. Remote receipt branch:
   - branch codex/boatops-d0-1-deployment-receipt;
   - head 10fa260fce3ec8708f180ce016e723e6c7ea4180;
   - direct parent 3826cb2c29aea4d2b92a90e04c14f8c218fbf45c;
   - only changed path:
     docs/releases/0.0.8-d0-1-deployment-receipt.md.
2. Historical raw command outputs:
   - FINAL_SOURCE_IDENTITY=PASS;
   - SQLITE_FINAL_IMMUTABILITY=PASS;
   - ROLLBACK_TEST=PASS;
   - current restored to D0_1_20260808T140625Z;
   - all relevant commands exited 0.
3. Independent live read-only recheck on 2026-08-09:
   - current release, source metadata, runtime configuration, SQLite snapshot,
     backup hashes, rollback receipt, and public HTTP behavior all matched.

### D0.1 source and runtime evidence

- Current release: D0_1_20260808T140625Z.
- Exact deployed SHA:
  3826cb2c29aea4d2b92a90e04c14f8c218fbf45c.
- The range adaf4035d4b91a6bd872954113da177a61604c8f..3826cb2c
  contains two governance commits and changes only .project/CURRENT_GATE.md,
  .project/CURRENT_STATE.yaml, and .project/REVIEW_QUEUE.md; no BoatOps
  business-source delta was introduced for deployment.
- Source-tree SHA-256:
  573256b3c62f4fdda495cb0742271ab773da8ec2bc6a1176fa3cb3a4c2e7c9a2.
- Release archive SHA-256:
  d98f3ba2d6ed2911008850e66d65e1500a06cf22389b7b0f326b597cba2d6261.
- Metadata environment: demo; metadata dataset: fictional.
- Runtime app environment: production; debug: false.
- BOATOPS_DEMO_SITE_ENABLED=true.
- BOATOPS_DEMO_SITE_MODE=public_read_only.
- BOATOPS_DEMO_SITE_ISOLATED_DATASET=true.
- BOATOPS_DEMO_SITE_ALLOW_PRODUCTION_SEED=false.
- Effective DB driver: sqlite; DB_URL absent.
- CACHE_STORE=file.
- SESSION_DRIVER=file.
- QUEUE_CONNECTION=sync.
- Queue service: inactive/disabled.
- No production Seeder or migration was executed during D0.1. The disabled
  production-seed flag remains effective.
- Dataset query: organizations=1 and organizations matching the configured
  Fictional Andaman Charter Lab boundary=1.

### D0.1 HTTP evidence

Independent current probes:

- GET /up, /, /demo, /demo/calendar, /demo/slots: 5/5 returned 200.
- HEAD/POST/PUT/PATCH/DELETE/OPTIONS against /, /demo, /demo/calendar,
  /demo/slots: 24/24 returned 405.
- GET/HEAD/POST/PUT/PATCH/DELETE/OPTIONS against /api,
  /api/v1/inventory/revision, /api/v1/holds, and
  /api/internal/v1/schedule/slot-offerings: 28/28 returned 404.
- Source inspection at the deployed SHA confirms RejectPublicDemoWrites is
  globally prepended, matches both api and api/* before routing/authentication,
  and rejects every remaining real method other than GET with 405. The live
  matrix therefore samples the prefix-wide, pre-controller guard rather than
  relying on individual route/controller behavior.

The first reviewer attempt used curl -X HEAD and timed out while waiting for a
body. Repeating with curl's real HEAD mode passed. This was a reviewer-command
error, not an application failure.

### D0.1 SQLite immutability evidence

The values before and after the independent HTTP matrix were identical:

- main SQLite SHA-256:
  97d7738c866fa5df6062650da25e644258a4e5c60255c6e1ad83e7ea65632ab4;
- SQLite artifact-set SHA-256:
  8ddd602261a3c167cf718060cc6f4cddec071de07ba345d4590cca79e4eb03cb;
- canonical row-state SHA-256:
  514b073dc1971da895454d5c7ec0bbe9603c9366ac8feb588eb137b640331fa0;
- integrity_check: ok;
- foreign-key violations: 0.

The complete row-count map also remained identical:

allocations=6, api_clients=3, audit_logs=8, blocks=1, boats=2,
bookings=4, cache=0, cache_locks=0, cash_accounts=1, cash_postings=1,
crew_assignments=0, crew_members=0, expense_categories=2, expense_lines=0,
expenses=0, failed_jobs=0, finance_reversals=0, fuel_logs=0, holds=1,
idempotency_keys=3, items=1, job_batches=0, jobs=0, migrations=16,
organizations=1, outbox_events=0, password_reset_tokens=0,
rate_snapshots=0, sessions=0, slot_compatibility_rules=11,
slot_offering_boats=3, slot_offerings=8, stock_balances=3,
stock_movements=3, trip_checklists=0, trip_templates=1, trips=4, users=0.

### D0.1 backup and rollback evidence

The retained backup directory exists and remains readable:

- previous-release archive SHA-256:
  91bb969d929fe0a44d52e5fed00446a200190f2f38189d3539ba6296b5c0fff1;
- configuration backup SHA-256:
  f015f47d44cc5ba94c0525260f4bc61350a64d8b3ffb9089ee68dfd8850230f1;
- scheduler backup SHA-256:
  549ca61e7e5e2ccf5b0910e9cb323cd94e57d475c6c407c9644aca178f815adc;
- backup SQLite main/artifact/canonical hashes match the live immutable values;
- backup SQLite integrity=ok and foreign-key violations=0;
- previous release directory 20260808T054205Z remains present.

The retained rollback receipt records the historical actual atomic test:

- switch to 20260808T054205Z;
- previous /up=200 and /demo=200;
- restore to D0_1_20260808T140625Z;
- restored /up=200 and /demo=200;
- ROLLBACK_TEST=PASS;
- final release D0_1_20260808T140625Z.

No new rollback switch was performed during governance closure.

The generic hermes verify timeout is not part of the frozen D0.1 acceptance
contract and no runtime defect was found behind that timeout.

## 5. P2 findings and Owner decisions

Remaining P2 risks:

1. Audit rows lack explicit request/idempotency correlation.
2. Coarse organization locking may constrain future high-throughput workloads.
3. Operator inquiry/block/audit listings remain unpaginated MVP surfaces.

OWNER_DECISION_REQUIRED:

- exact Plan A / Plan B schedules;
- buffer values;
- HOLD TTL, extension, and re-HOLD rules;
- slot combination and mutual-exclusion rules;
- weather policy;
- custom-slot policy;
- production Operator identities and permission mapping;
- real product, price, customer, and order configuration.

## 6. Authorization and recommendation

- merge_authorized=false
- deployment_authorized=false
- tag_authorized=false
- release_authorized=false
- production_data_authorized=false

Recommended Owner merge decision:

Authorize merge of the exact governance head only after GitHub CI succeeds for
that head. This recommendation does not authorize deployment, Tag, Release,
real data, or any further business-code change.

READY_FOR_OWNER_MERGE_DECISION
