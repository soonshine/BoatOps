# BoatOps Review Queue

Last updated: `2026-08-08 13:50 Asia/Bangkok`

Current decision: `NO_GO_MERGE`

## Open blockers

| ID | Priority | Status | Owner | Finding | Reproduction evidence | Acceptance |
| --- | --- | --- | --- | --- | --- | --- |
| G0-REV-001 | P0 | OPEN | Hermes | Production-capable `DemoSiteSeeder` invokes an unscoped `SlotCatalogSeeder` that loops through all organizations. | Unrelated org: slot offerings `0 -> 5` after one production public Demo seed. | Demo seed changes only the exact fictional org; unrelated offerings and rules remain unchanged. |
| G0-REV-002 | P0 | OPEN | Hermes | `public_read_only` guards only `/demo/*`; the application API remains reachable with valid credentials. | Valid fictional credential: `POST /api/v1/holds -> 422`; `api_clients.last_used_at` changed from null to non-null. | `/api/*` is closed before auth in public mode; all non-GET writes are rejected; all tracked row counts and revisions remain unchanged. |
| G0-REV-003 | P0 | OPEN | Hermes | Database cache/session defaults make a public GET mutate the Demo SQLite database. | With database cache/session: `GET /demo -> 200`, cache `0 -> 2`, sessions `0 -> 1`, SQLite SHA changed. | Approved public settings cannot write the app DB; GET leaves hash and relevant counts unchanged; unsafe settings fail closed. |
| G0-REV-004 | P0 | OPEN | Hermes | Public mode has no enforceable isolated-dataset/driver gate. | Code gate checks environment/mode but not an isolation flag or database architecture. | Public serving and production seeding require explicit isolation flag plus SQLite for current D0; missing/mismatched configuration fails before DB access. |

## Code pointers

- `database/seeders/DemoSiteSeeder.php:67`
- `database/seeders/SlotCatalogSeeder.php:52`
- `app/Http/Middleware/RejectPublicDemoWrites.php:13`
- `app/Http/Middleware/AuthenticateApiClient.php:36`
- `app/Http/Middleware/ResolveDemoSiteContext.php:25`
- `.env.example:40`
- `.env.example:50`

## Verified passes

| Area | Evidence | Result |
| --- | --- | --- |
| Branch identity | Remote `main=c9200439...`, Demo branch `c10f3a2...` | PASS |
| PHPUnit | 121 tests, 1,367 assertions | PASS |
| Formatting | Pint | PASS |
| Contracts | Inventory and Operations OpenAPI plus event fixtures | PASS |
| Frontend | Vite production build | PASS |
| PHP dependencies | strict Composer validation; Composer audit | PASS |
| Node dependencies | npm audit | PASS, 0 vulnerabilities |
| Git secret scan | 160 tracked files and 317 unique history objects | PASS, 0 findings |
| GitHub CI | Run 31243002377 for `c10f3a2...` | PASS |
| Public HTTP | five approved GET paths | PASS, 200 |
| Public Demo UI boundary | no POST form, password field, credential marker, or local marker | PASS |
| Public Demo POST boundary | `/demo/calendar`, `/demo/fuel` | PASS, 405 |

## Evidence limitations

- The public response does not expose a trustworthy release-commit identity; deployed commit `011cd81...` remains deployment-receipt evidence, not independently re-proven from runtime.
- Physical SQLite isolation is documented in the deployment receipt but cannot be independently proven from outside the server.
- No merge, deployment, Tag, or Release has been authorized during this review.

## Review rule

When Hermes completes `G0_READ_ONLY_ISOLATION_HARDENING`, add its commit, exact test evidence, CI link, and remaining limitations here. Do not close any item based only on Hermes or Claude Code saying it is complete; ChatGPT must inspect and reproduce it.
