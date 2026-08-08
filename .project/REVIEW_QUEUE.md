# BoatOps Review Queue

Last updated: `2026-08-08 18:37 Asia/Bangkok`

Current decision: `G0_CODE_REVIEW_APPROVED / OWNER_MERGE_AUTHORIZED`

## Closed G0 blockers

| ID | Priority | Status | Reviewer | Independent reproduction evidence | Acceptance result |
| --- | --- | --- | --- | --- | --- |
| G0-REV-001 | P0 | CLOSED | ChatGPT | Production Demo seed plus the default `DatabaseSeeder` chain left an unrelated organization's sentinel offerings and compatibility rule unchanged; unexpected writes `0`. | Exact fictional organization only: PASS. |
| G0-REV-002 | P0 | CLOSED | ChatGPT | Valid fictional Bearer credential received `404` for the API before auth; `last_used_at`, HOLDs, and canonical database state remained unchanged. Non-GET non-API requests received `405`. | API closed and writes rejected before controllers: PASS. |
| G0-REV-003 | P0 | CLOSED | ChatGPT | Approved file cache/session runtime returned `GET /demo = 200`; SQLite rows/artifacts, cache rows, session rows, and job rows were unchanged. Database-backed state drivers returned `404` with `0` SQL. | Public GET cannot mutate the application SQLite database: PASS. |
| G0-REV-004 | P0 | CLOSED | ChatGPT | Missing/false isolation, PostgreSQL default, PostgreSQL `DB_URL`, database cache, database session, and database queue each returned `404` with `0` SQL queries. Invalid production seed gates also failed before SQL. | Explicit isolated SQLite contract enforced: PASS. |

## Frozen review identities

| Identity | Commit / run | Status |
| --- | --- | --- |
| G0 remediation implementation | `6e2b6efaee81fbaabcb3b5c522abc8c95a1cc4ca` | REVIEWED |
| G0 code baseline | `adaf4035d4b91a6bd872954113da177a61604c8f` | APPROVED / FROZEN |
| Code-baseline CI | [Run 31254772199](https://github.com/soonshine/BoatOps/actions/runs/31254772199) | SUCCESS |
| Main before alignment | `c920043950e80a0a60ca88a83e440fc3b9882b94` | VERIFIED |
| Deployed Demo branch | `c10f3a2eb2769a2f30f346906131b3c07c95e111` | UNCHANGED |
| Governance head | Dedicated `.project`-only child of `adaf4035...` | PENDING COMMIT / CI |

## Independent verified passes

| Area | Evidence | Result |
| --- | --- | --- |
| Exact code diff | `547198e3...adaf4035`; allowed G0 remediation, tests, documentation, and governance evidence only | PASS |
| PHPUnit | 130 tests, 1,482 assertions | PASS |
| Targeted Demo regression set | 38 tests, 520 assertions | PASS |
| File-backed SQLite GET regression | 1 test, 10 assertions re-run on final code head | PASS |
| Formatting | Pint | PASS |
| Contracts | Inventory and Operations OpenAPI plus event fixtures | PASS |
| Frontend | Vite production build | PASS |
| PHP dependencies | strict Composer validation; locked dependency audit | PASS, 0 advisories |
| Node dependencies | npm audit | PASS, 0 vulnerabilities |
| SQLite lifecycle | fresh migration, rollback, remigration, integrity check | PASS |
| Git secret scan | 166 tracked files and 358 history objects | PASS, current/history 0 findings |
| GitHub CI | Exact `adaf4035...` head | PASS |
| Public HTTP | `/up`, `/`, `/demo`, `/demo/calendar`, `/demo/slots` | PASS, 200 |
| Public Demo POST boundary | `/demo/calendar`, `/demo/fuel` | PASS, 405 |

## Scope and deployment findings

- No Operator MVP, finance, stock, Google Sheet, ChannelHub, OTA, payment,
  WordPress, deployment, Tag, Release, or real-data change is part of this
  governance step.
- The live Demo remains the earlier candidate. Its unauthenticated Inventory API
  probe returned `401`, whereas the approved G0 source returns `404`; therefore
  the G0 hardening has not been presented as deployed.
- The public response does not expose a trustworthy release-commit identity.
  Deployed commit `011cd81...` remains deployment-receipt evidence rather than
  independently proven runtime identity.
- Physical SQLite isolation is recorded by the deployment receipt but cannot be
  proven from outside the server.

## Merge authorization and remaining controls

Owner authorization is granted only for a fast-forward alignment of the G0 code
baseline plus its `.project`-only governance descendant into `main`, after exact
governance-head CI success.

Still prohibited:

- deployment;
- Tag or GitHub Release;
- production/real data;
- new business code;
- changes to the live Demo;
- widening the authorized merge range after the governance head is frozen.

After the merge, ChatGPT must independently verify the new remote `main`, its
ancestry and CI, then stop before any G1 implementation.
