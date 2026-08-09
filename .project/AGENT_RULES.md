# BoatOps Agent Rules

Status: `MANDATORY`

Every agent that plans, implements, reviews, deploys, or reports on BoatOps must follow this file.

## 1. Required startup sequence

Before doing work, read in this order:

1. `.project/PROJECT_CHARTER.md`
2. `.project/CURRENT_STATE.yaml`
3. `.project/CURRENT_GATE.md`
4. `.project/REVIEW_QUEUE.md`
5. the relevant gate/closure receipt under `.project/**`
6. the exact Git diff and tests in the assigned scope

Then report the current gate, working commit, authorization flags, task ID, and excluded scope. If files disagree with chat history, the project files and reviewed Git evidence win unless the Owner explicitly supersedes them.

## 2. Current hard stops after D1

D1 is a completed fictional Demo deployment/validation gate. Until the Owner defines and authorizes the next product gate:

- do not change BoatOps business code;
- do not deploy a new product/runtime revision;
- do not create a Tag or GitHub Release;
- do not enable production inventory;
- do not migrate/import real data;
- do not connect Google Sheet, ChannelHub, OTA, payments, WordPress, or real credentials;
- do not reinterpret fictional Demo values as real operating rules;
- do not treat the current two-vessel scenario as Ayany-owned assets;
- do not start a gate named `G2A`, `G2B`, or any other product gate until its objective and acceptance criteria are explicitly recorded and Owner-authorized.

Governance-only work may update `.project/**`, README, and non-secret release/evidence documentation when explicitly authorized, but it must not mutate runtime, database, source behavior, or production configuration.

## 3. Product portability rule

BoatOps is a reusable organization-scoped product.

- Ayany must not be hard-coded as tenant, vessel owner, operator, or required integration.
- Vessel ownership and operating rights are not inferred from deployment hostname or demo fixtures.
- Plan A / Plan B names, schedules, buffers, TTLs, prices, compatibility, weather rules, and operator identities are deployment/configuration data unless a gate explicitly freezes them.
- Another organization must be able to deploy BoatOps without Ayany-specific code paths.

## 4. Role contract

- Owner supplies product direction and real business rules, and grants merge/deployment/data authority.
- ChatGPT reviewer defines scope, reviews code/evidence, records blockers, and decides the gate.
- Hermes implements or executes the bounded task and supplies evidence.
- Claude Code is optional. Hermes may delegate implementation to it when available, but Claude failure must not alter scope or block Hermes from completing an otherwise executable task.
- Codex is an optional independent milestone reviewer when explicitly requested; it is not the default executor and does not replace Owner authority.

An implementation/execution agent must not mark its own gate `APPROVED` or `COMPLETE`.

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

If an agent discovers adjacent work, add it to `REVIEW_QUEUE.md` with evidence and stop at the current task boundary. Do not silently expand implementation.
