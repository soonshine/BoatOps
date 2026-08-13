# BoatOps Agent Rules

Status: `MANDATORY`

Every agent that plans, implements, reviews, deploys, or reports on BoatOps must follow this file.

## 1. Required startup sequence

Before doing work, read in this order:

1. `.project/CURRENT_STATE.yaml`
2. `.project/CURRENT_GATE.md`
3. the exact task code, diff, and tests in the assigned scope

Read `.project/PROJECT_CHARTER.md` only when a product boundary or invariant is relevant. Read `.project/REVIEW_QUEUE.md` only when triaging blockers or observed pain. Read historical receipts only when validating a concrete historical claim.

The current state, current gate, exact Git evidence, and newer explicit Owner authorization outrank stale historical receipts. Do not manufacture a governance preamble for routine progress.

## 2. Current execution model and hard stops

Routine progress follows:

```text
real problem
-> smallest task
-> implementation PR
-> test
-> merge
-> TEST / real use
-> observed pain
-> next minimum change
```

`NO_GOVERNANCE_PR_FOR_ROUTINE_PROGRESS`

Do not create a governance-only PR, new Gate, readiness matrix, or work package merely to advance routine implementation. State/document updates should normally travel in the same relevant PR.

No new feature development is allowed unless it addresses:

- a `REAL_PILOT_BLOCKER`;
- `OBSERVED_OPERATIONAL_PAIN`;
- a `UNIVERSAL_CORE_SAFETY_DEFECT`.

Keep these hard stops:

- no Production deployment without explicit authorization;
- no Cutover or authority switch without explicit authorization;
- no Tag or Release without explicit authorization;
- no real-data import or historical migration outside an explicitly authorized scope;
- no invented business facts, real credentials, or secrets in Git or reports.

## 3. Product portability rule

BoatOps is a reusable organization-scoped product.

- Ayany must not be hard-coded as tenant, vessel owner, operator, or required integration.
- Vessel ownership and operating rights are not inferred from deployment hostname or demo fixtures.
- Plan A / Plan B names, schedules, buffers, TTLs, prices, compatibility, weather rules, and operator identities are deployment/configuration data unless a gate explicitly freezes them.
- Another organization must be able to deploy BoatOps without Ayany-specific code paths.

## 4. Role contract

- Owner supplies product direction and real business rules, and grants merge/deployment/data authority.
- Reviewer checks the bounded diff and exact evidence when review is requested.
- Executor implements or runs only the authorized bounded task and supplies exact evidence.
- Delegation or tool choice never changes scope or authority.

An implementation/execution agent may report verified completion of its task but cannot grant Merge, Deployment, Cutover, or Release authority that the Owner did not provide.

## 5. Evidence contract

Every implementation handoff must include:

- starting commit and final commit;
- exact files changed;
- why each change is in scope;
- complete test commands and exit codes;
- test counts and assertion counts where applicable;
- a clean `git status` result;
- current GitHub CI URL and conclusion after push;
- explicit `NOT_MERGED / NOT_DEPLOYED / NOT_TAGGED / NOT_RELEASED` status unless separately authorized;
- any item that remains unverified, written as `NOT_VERIFIED` rather than guessed.

Deployment handoffs must additionally record, without secrets:

- exact source SHA;
- release identifier;
- dataset class;
- runtime database type;
- rollback/restore status when required by the gate;
- integrity/checksum evidence required by the gate;
- explicit production/real-data status.

Static inspection is not runtime proof. A successful local test is not public deployment proof. An executor report is not independent review.

## 6. Git discipline

- Start from the baseline recorded in `CURRENT_STATE.yaml` unless the gate explicitly changes it.
- Use a dedicated branch for bounded work.
- Preserve user changes and stop if the worktree contains unexplained modifications.
- Keep commits single-purpose and reviewable.
- Do not rewrite shared branch history.
- Do not merge, Tag, Release, deploy, migrate data, or enable production while the relevant authorization flag is false.
- Abandoned/superseded branches are never valid implementation baselines merely because they contain later commits.

## 7. Demo safety rules

- `public_read_only` means the whole served public Demo instance is read-only, not merely the `/demo` HTML forms.
- Public Demo mode must close application API routes and all non-GET application writes before authentication and controllers.
- Public Demo mode must fail closed unless an explicit isolated-dataset gate is true and the configured data store matches the approved Demo architecture.
- Database-backed rate limiting, sessions, cache, or queues must not mutate the Demo application database during a public GET.
- A Demo seeder may write only the explicitly resolved fictional organization. It may not iterate through every organization.
- Tests must include an unrelated organization and prove zero cross-organization writes where the gate requires seeding/isolation validation.
- Synthetic times remain `DEMO_DEFAULT_UNVERIFIED` or `FICTIONAL_VALIDATION_SCENARIO` until real rules are separately frozen.
- A private fictional Operator surface may be used for bounded validation only when access and isolation match the approved deployment evidence.

## 8. Production inventory rules

- PostgreSQL transaction results are authoritative for conflicts in production.
- Final HOLD and confirmation re-adjudicate the occupied interval.
- UI simulations, cached availability, spreadsheets, and ChannelHub cannot reserve inventory.
- Amend, cancel, release, expiry, and block operations require atomic state changes, inventory revision changes, and audit evidence.
- Never treat Google Sheet availability as authoritative after BoatOps is approved as production truth.

## 9. Security rules

- Never paste or print a secret.
- Use environment references or an approved secret source.
- Never inspect cookies, passwords, browser storage, or session stores.
- Do not save real credentials in test fixtures, screenshots, commands, reports, or Git.
- If a credential appears in a log, stop feature work and run the rotation/revocation/cleanup/Git-scan gate first.

## 10. Scope drift rule

Do not silently expand implementation. Record adjacent work only when it is a proven Real Pilot blocker, observed operational pain, or universal core-safety defect. Routine state updates belong in the same relevant PR; unproven future ideas are not automatically queued and do not justify a governance PR.
