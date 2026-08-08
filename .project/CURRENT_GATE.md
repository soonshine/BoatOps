# Current Gate: G0 Project Alignment

Status: `APPROVED_PENDING_MERGE`

Code review decision: `APPROVED`

Technical merge decision: `GO`

Owner merge authorization: `GRANTED_FOR_EXACT_G0_SCOPE`

Deployment decision: `NO_GO`

Tag decision: `NO_GO`

Release decision: `NO_GO`

Real-data decision: `NO_GO`

## Objective

Create one trustworthy development baseline for BoatOps. Preserve the currently
deployed fictional Demo while aligning the independently reviewed G0 code and
governance history into `main`. This gate does not authorize deployment or any
Operator MVP business implementation.

## Frozen identities

- G0 code baseline: `adaf4035d4b91a6bd872954113da177a61604c8f`
- Remediation implementation: `6e2b6efaee81fbaabcb3b5c522abc8c95a1cc4ca`
- Test-only stabilization: `adaf4035d4b91a6bd872954113da177a61604c8f`
- Main before alignment: `c920043950e80a0a60ca88a83e440fc3b9882b94`
- Deployed Demo branch: `c10f3a2eb2769a2f30f346906131b3c07c95e111`
- Deployed Demo implementation receipt: `011cd81c7fd7907086178db735dbebc28abe7b61`
- Reviewed code CI: [GitHub Actions 31254772199](https://github.com/soonshine/BoatOps/actions/runs/31254772199), `success`

`adaf4035...` is immutable for this gate. The governance update must be a
dedicated descendant that changes only `.project` files. The code baseline and
the governance head are intentionally distinct audit identities.

## Closed review findings

### G0-REV-001 — CLOSED

`DemoSiteSeeder` now calls an organization-scoped slot catalog path. ChatGPT's
independent production-mode reproduction preserved the unrelated organization's
offerings and compatibility rules exactly; unexpected writes were `0`.

### G0-REV-002 — CLOSED

Public read-only mode closes `/api` and `/api/*` before API authentication and
rejects all remaining non-GET requests before routing/controllers. A valid
fictional credential received `404`; `api_clients.last_used_at`, HOLD counts,
and the application database state remained unchanged.

### G0-REV-003 — CLOSED

Approved public runtime settings require file cache, file rate limiting, file
sessions, and the sync queue. An independent public `GET /demo` returned `200`
without changing SQLite row state or file artifacts. Database cache, session,
or queue configurations returned `404` before any SQL query.

### G0-REV-004 — CLOSED

Public serving and production Demo seeding now require the explicit isolated
dataset flag and an effective SQLite configuration. Missing/false isolation,
PostgreSQL defaults, and a PostgreSQL `DB_URL` returned `404` with `0` SQL
queries. Production seeding failed before SQL when the contract was invalid.

## Independent validation

- PHPUnit: `130/130` tests, `1,482` assertions.
- Targeted Demo regressions: `38/38` tests, `520` assertions.
- Pint: passed.
- Inventory and Operations contracts and event fixtures: passed.
- Vite production build: passed.
- Composer `validate --strict` and `audit --locked`: passed; `0` advisories.
- npm audit: passed; `0` vulnerabilities.
- SQLite `migrate:fresh -> rollback -> migrate`: passed; `integrity_check=ok`.
- Secret scan: `166` tracked files and `358` history objects; `0` findings.
- Exact-head GitHub CI: passed for `adaf4035...`.
- Worktree and remote identities were clean and matched at approval time.

## Owner authorization

The Owner authorized this exact sequence on `2026-08-08` (Asia/Bangkok):

1. create one governance-only descendant of `adaf4035...`;
2. push it and require GitHub CI success for that exact governance head;
3. fast-forward the complete G0 baseline into `main`;
4. stop business development and independently verify the new remote `main`.

This authorization does not include deployment, Tag, GitHub Release, real-data
access/import, Google Sheet, ChannelHub, OTA, payment, WordPress, finance/stock
expansion, or Operator MVP implementation.

## Merge protocol

1. Confirm the governance diff contains only `.project` files.
2. Commit as `docs: close G0 remediation review` with parent `adaf4035...`.
3. Push the remediation branch and wait for exact-head CI success.
4. Reconfirm remote `main=c920043...` and a clean worktree.
5. Fast-forward `main` to the governance head; do not rewrite history.
6. Push `main`, wait for its CI, and independently verify the remote commit,
   ancestry, changed paths, authorization boundaries, and live Demo separation.

## Gate boundary

Until remote `main` is independently verified after the authorized merge:

`NO_BUSINESS_CHANGES / NOT_DEPLOYED / NOT_TAGGED / NOT_RELEASED / NO_REAL_DATA`
