# BoatOps Review Queue

Last updated: `2026-08-08 18:05 Asia/Bangkok`

Current decision: `NO_GO_MERGE_PENDING_CHATGPT_REVIEW`

## Open blockers

| ID | Priority | Status | Owner | Finding | Reproduction evidence | Acceptance |
| --- | --- | --- | --- | --- | --- | --- |
| G0-REV-001 | P0 | REMEDIATED_PENDING_CHATGPT_REVIEW | Hermes | Production-capable `DemoSiteSeeder` invoked an unscoped `SlotCatalogSeeder`. | `6e2b6efaee81fbaabcb3b5c522abc8c95a1cc4ca` scopes the Demo catalog and removes the default-chain bypass; production regression preserves unrelated offerings/rules byte-for-byte. | Demo seed changes only the exact fictional org; unrelated offerings and rules remain unchanged. |
| G0-REV-002 | P0 | REMEDIATED_PENDING_CHATGPT_REVIEW | Hermes | `public_read_only` left the application API reachable with valid credentials. | `6e2b6efaee81fbaabcb3b5c522abc8c95a1cc4ca` closes `/api` and `/api/*` before auth and rejects actual non-GET methods; valid fictional Bearer matrix leaves API metadata and application rows unchanged. | `/api/*` is closed before auth in public mode; all non-GET writes are rejected; all tracked row counts and revisions remain unchanged. |
| G0-REV-003 | P0 | REMEDIATED_PENDING_CHATGPT_REVIEW | Hermes | Database cache/session defaults made a public GET mutate the Demo SQLite database. | `6e2b6efaee81fbaabcb3b5c522abc8c95a1cc4ca` requires file cache/session, file limiter, and sync queue; WAL-mode file SQLite test compares artifact hash, row hash, and counts; unsafe drivers fail before SQL. | Approved public settings cannot write the app DB; GET leaves hash and relevant counts unchanged; unsafe settings fail closed. |
| G0-REV-004 | P0 | REMEDIATED_PENDING_CHATGPT_REVIEW | Hermes | Public mode lacked an enforceable isolated-dataset/driver gate. | `6e2b6efaee81fbaabcb3b5c522abc8c95a1cc4ca` adds strict boolean defaults, explicit isolation, effective/nested `DB_URL` SQLite validation, production seed gating, and zero-query regressions. | Public serving and production seeding require explicit isolation flag plus SQLite for current D0; missing/mismatched configuration fails before DB access. |

## Code pointers

- `database/seeders/DatabaseSeeder.php:20`
- `database/seeders/DemoSiteSeeder.php:22,68,99`
- `database/seeders/SlotCatalogSeeder.php:8,15`
- `app/Http/Middleware/RejectPublicDemoWrites.php:13`
- `app/Http/Middleware/AuthenticateApiClient.php:36`
- `app/Http/Middleware/ResolveDemoSiteContext.php:15,128`
- `.env.example:8,42,50`

## Verified passes

| Area | Evidence | Result |
| --- | --- | --- |
| Branch identity | Remote `main=c9200439...`, Demo branch `c10f3a2...` | PASS |
| Remediation commit | `6e2b6efaee81fbaabcb3b5c522abc8c95a1cc4ca`, parent `547198e3a2e9e4c058803f0f58529bc997fa2542` | PUSHED |
| PHPUnit | 130 tests, 1,482 assertions | PASS |
| Formatting | Pint | PASS |
| Contracts | Inventory and Operations OpenAPI plus event fixtures | PASS |
| Frontend | Vite production build | PASS |
| PHP dependencies | strict Composer validation; Composer audit | PASS |
| Node dependencies | npm audit | PASS, 0 vulnerabilities |
| Git secret scan | 166 tracked files and 325 history objects | PASS, current/history 0 findings |
| GitHub CI | [Run 31254144086](https://github.com/soonshine/BoatOps/actions/runs/31254144086) for `6e2b6efa...` | PASS |
| Public HTTP | five approved GET paths | PASS, 200 |
| Public Demo UI boundary | no POST form, password field, credential marker, or local marker | PASS |
| Public Demo POST boundary | `/demo/calendar`, `/demo/fuel` | PASS, 405 |

## Evidence limitations

- The public response does not expose a trustworthy release-commit identity; deployed commit `011cd81...` remains deployment-receipt evidence, not independently re-proven from runtime.
- Physical SQLite isolation is documented in the deployment receipt but cannot be independently proven from outside the server.
- ChatGPT must independently reproduce and review this remediation before any G0 merge decision; Hermes has not closed the gate.
- No merge, deployment, Tag, or Release has been authorized during this review.

## Review rule

When Hermes completes `G0_READ_ONLY_ISOLATION_HARDENING`, add its commit, exact test evidence, CI link, and remaining limitations here. Do not close any item based only on Hermes or Claude Code saying it is complete; ChatGPT must inspect and reproduce it.
