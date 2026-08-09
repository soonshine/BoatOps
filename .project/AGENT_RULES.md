# BoatOps Agent Rules

Status: `MANDATORY`

Every agent that plans, implements, reviews, deploys, or reports on BoatOps must follow this file.

## 1. Required startup sequence

Before doing work, read in this order:

1. `.project/PROJECT_CHARTER.md`
2. `.project/CURRENT_STATE.yaml`
3. `.project/CURRENT_GATE.md`
4. `.project/REVIEW_QUEUE.md`
5. the exact Git diff and tests in the assigned scope

Then report the current gate, working commit, authorization flags, task ID, and excluded scope. If the files disagree with chat history, the project files and reviewed Git evidence win unless the owner explicitly changes them.

## 2. Current hard stops

After G1 reached `COMPLETE` on `main`:

- do not change BoatOps business code; the reviewed code head is frozen at
  `20978a169bbd52278b3bc4ab36e901a55c7e0b00`;
- do not merge the post-merge receipt branch or any later branch to `main`
  without a new explicit Owner authorization;
- do not deploy G1;
- do not create a Tag or GitHub Release;
- do not migrate or import real data;
- do not connect Google Sheet, ChannelHub, OTA, payments, WordPress, or real credentials;
- do not change the live Demo.

The current authorization covers only the post-merge governance receipt under
`.project/**`. After that receipt is pushed and its exact-head CI succeeds,
wait for the next gate definition and a new explicit Owner authorization.

## 3. Role contract

- Owner supplies real business rules and grants merge/deployment authority.
- ChatGPT reviewer defines scope, reviews code and evidence, records blockers, and decides the gate.
- Hermes implements the bounded task and supplies evidence.
- Claude Code is optional. Hermes may delegate implementation to it when available, but Claude failure or 401 must not alter scope or block Hermes from completing the task.
- Codex performs an optional independent milestone review only when requested.

An implementation agent must not mark its own gate `APPROVED`.

## 4. Evidence contract

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

Static inspection is not runtime proof. A successful local test is not public deployment proof. An agent report is not independent review.

## 5. Git discipline

- Start from the commit recorded in `CURRENT_STATE.yaml` unless the gate explicitly changes the baseline.
- Use a dedicated branch for remediation; do not stack unrelated features onto the public Demo branch.
- Preserve user changes and stop if the worktree contains unexplained modifications.
- Keep commits single-purpose and reviewable.
- Do not rewrite shared branch history.
- Do not merge, Tag, Release, or deploy while the relevant authorization flag is false.

## 6. Demo safety rules

- `public_read_only` means the whole served Demo instance is read-only, not merely the `/demo` HTML forms.
- Public Demo mode must close application API routes and all non-GET application writes before authentication and controllers.
- Public Demo mode must fail closed unless an explicit isolated-dataset gate is true and the configured data store matches the approved Demo architecture.
- Database-backed rate limiting, sessions, cache, or queues must not mutate the Demo application database during a public GET.
- A Demo seeder may write only the explicitly resolved fictional organization. It may not iterate through every organization.
- Tests must include an unrelated organization and prove zero cross-organization writes.
- Synthetic times remain `DEMO_DEFAULT_UNVERIFIED` or `FICTIONAL_VALIDATION_SCENARIO` until the owner freezes real rules.

## 7. Production inventory rules

- PostgreSQL transaction results are authoritative for conflicts.
- Final HOLD and confirmation re-adjudicate the occupied interval.
- UI simulations, cached availability, and ChannelHub cannot reserve inventory.
- Amend, cancel, release, expiry, and block operations require atomic state changes, inventory revision changes, and audit evidence.
- Never treat Google Sheet availability as authoritative after BoatOps is approved as production truth.

## 8. Security rules

- Never paste or print a secret.
- Use environment references or an approved secret source.
- Never inspect cookies, passwords, browser storage, or session stores.
- Do not save real credentials in test fixtures, screenshots, commands, reports, or Git.
- If a credential appears in a log, stop feature work and run the rotation/revocation/cleanup/Git-scan gate first.

## 9. Scope drift rule

If an agent discovers adjacent work, add it to `REVIEW_QUEUE.md` with evidence and stop at the current task boundary. Do not silently expand the implementation.
