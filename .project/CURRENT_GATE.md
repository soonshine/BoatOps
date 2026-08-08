# Current Gate: G0 Project Alignment

Status: `BLOCKED_FOR_REMEDIATION`

Merge decision: `NO_GO`

Deployment decision: `NO_GO`

## Objective

Create a trustworthy single development baseline before Operator MVP work begins. Preserve the currently deployed, isolated public Demo while eliminating code paths that could violate Demo read-only or data-isolation guarantees.

## Completed evidence

- `P0-SEC-001` credential rotation, new-key verification, old-key revocation, local cleanup, and Git-history scan: complete.
- Remote branch identity rechecked: `main` remains `c920043950e80a0a60ca88a83e440fc3b9882b94`; Demo branch remains `c10f3a2eb2769a2f30f346906131b3c07c95e111`.
- Current public GET acceptance: `/up`, `/`, `/demo`, `/demo/calendar`, and `/demo/slots` returned 200.
- Current public Demo pages returned `no-store` and `noindex, nofollow, noarchive`, contained no POST form, password field, credential marker, or `LOCAL ONLY` marker.
- Current Demo POSTs tested at `/demo/calendar` and `/demo/fuel` returned 405.
- Local baseline: PHPUnit 121/121 with 1,367 assertions; Pint, contract validation, frontend build, strict Composer validation, Composer audit, and npm audit passed.
- GitHub CI for `c10f3a2...` passed.
- Current tracked files and all Git-history blobs passed the targeted secret scan with zero findings.

These passes prove the deployed candidate is currently usable as a fictional public Demo. They do not clear the merge blockers below.

## Blocking review findings

### G0-REV-001 — Demo seeder writes to unrelated organizations

`DemoSiteSeeder` calls `SlotCatalogSeeder`, and `SlotCatalogSeeder` iterates every organization. An isolated reproduction created an unrelated organization with zero slot offerings; running the authorized production Demo seed added five slot offerings to that unrelated organization.

Required remediation:

- provide an organization-scoped slot-catalog seeding path;
- make `DemoSiteSeeder` call only that scoped path for the exact fictional organization;
- add a production-mode regression test with an unrelated organization;
- prove unrelated slot offerings and compatibility rules remain byte-for-byte/count-for-count unchanged.

### G0-REV-002 — Public read-only mode leaves the application API active

The global write guard currently matches only `demo` and `demo/*`. In an isolated reproduction, a valid fictional Demo credential reached `POST /api/v1/holds`, returned 422 instead of a read-only rejection, and updated `api_clients.last_used_at`.

Required remediation:

- in `public_read_only`, close every `/api/*` route for every method before authentication;
- reject all remaining non-GET application requests before controller execution;
- add tests using a valid fictional credential;
- prove API client metadata, HOLDs, bookings, allocations, audit logs, inventory revision, finance, stock, cache, sessions, and queues do not change.

### G0-REV-003 — Default cache/session settings make a public GET write SQLite

`.env.example` defaults both cache and session storage to the database. In an isolated `production + public_read_only` reproduction, one GET `/demo` returned 200 but added two cache rows, one session row, and changed the SQLite SHA-256.

Required remediation:

- make public Demo mode fail closed when cache or session uses the application database, or remove those stateful database middleware paths for public Demo requests;
- document and test the approved public Demo settings (`CACHE_STORE=file`, `SESSION_DRIVER=file`, and no database queue writes from public requests);
- add a file-backed SQLite test proving a public GET leaves the application database hash and row counts unchanged.

### G0-REV-004 — No enforceable isolated-dataset gate

`public_read_only` currently requires only production environment, enable flag, mode, and matching fictional records. Code does not prevent the mode or production seeder from using a shared production PostgreSQL database.

Required remediation for the current D0 architecture:

- add an explicit isolated-dataset configuration flag that defaults false;
- require that flag for public serving and production Demo seeding;
- require the approved D0 database driver (`sqlite`) while this gate is active;
- fail closed before any database read/write when the isolation contract is not satisfied;
- test missing flag, false flag, and a non-SQLite configured connection.

## Authorized Hermes task

Task ID: `G0_READ_ONLY_ISOLATION_HARDENING`

Hermes may use Claude Code if available. If Claude Code fails or returns 401, Hermes must implement directly without changing scope.

Allowed changes:

- the two Demo middleware classes and their registration if needed;
- Demo and slot-catalog seeders;
- Demo configuration and `.env.example`;
- Demo documentation;
- narrowly related feature tests.

Forbidden changes:

- Operator MVP UI or business actions;
- HOLD/confirm/amend/cancel semantics unrelated to the guard test;
- finance or stock feature expansion;
- Google Sheet, ChannelHub, OTA, payment, or WordPress work;
- deployment, merge, Tag, Release, or real-data operations.

## Required validation

1. New targeted tests reproduce all four findings before the fix and pass after the fix.
2. Full PHPUnit passes with the new total test/assertion count.
3. Pint passes.
4. Contract suite passes.
5. Production frontend build passes.
6. Strict Composer validation and Composer audit pass.
7. npm audit passes with zero vulnerabilities.
8. Isolated SQLite migration, rollback, remigration, and integrity check pass.
9. Repository current-tree and all-history secret scan remains zero.
10. Existing public Demo tests remain green; no business behavior is added.
11. Push the remediation branch and wait for GitHub CI success.

## Exit criteria

G0 may be reconsidered only after ChatGPT independently reproduces the new regression tests, reviews the exact diff, confirms all blockers closed, and records a new merge decision. Until then:

`NOT_MERGED / NOT_DEPLOYED / NOT_TAGGED / NOT_RELEASED`
