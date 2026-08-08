# BoatOps Demo Site: Public Read-Only Candidate

Status: `CANDIDATE / LOCAL_WORKTREE / NOT_DEPLOYED / NOT_RELEASED`

This document describes the Gate D0 candidate only. It does not authorize deployment or claim that BoatOps is the production inventory master.

## Runtime modes

- `disabled` (default): all `/demo` paths fail closed with 404.
- `local_write`: local/testing only; fictional local write routes remain available for automated tests and development.
- `public_read_only`: production only; only GET `/demo`, `/demo/calendar`, and `/demo/slots` are intended for public access.

Public mode requires the explicit enable flag and mode, the exact fictional organization and two fictional boats, and the dedicated `Public Demo Reader`. Its scopes are exactly:

- `operations.finance.read`
- `operations.schedule.read`

It has no write scope. Demo POST routes are rejected before controller execution with 405. Responses include `X-Robots-Tag: noindex, nofollow, noarchive` and `Cache-Control: no-store`; GET requests are rate limited.

The early method gate runs before the normal web CSRF middleware, so a public POST cannot be changed into a CSRF-dependent code path or reach a write controller. `disabled` remains fail-closed for every method.

## Calendar simulation

The calendar's “simulate selection” control is a GET query only. The server reuses the existing slot interval resolver, compatibility policy, allocation projection, HOLD/CONFIRMED/BLOCK status logic, and whole-boat occupied intervals. It adds no allocation, HOLD, booking, audit, finance, or inventory revision record. The final HOLD or confirmation must still be re-adjudicated by the transactional BoatOps command path.

## Fictional data boundary

Seeder records are synthetic and relative to the organization-local Bangkok date. Preset operating times remain `DEMO_DEFAULT_UNVERIFIED`; date-specific examples remain `FICTIONAL_VALIDATION_SCENARIO`. Plan A and Plan B are whole-boat demo resources, not frozen real operating schedules.

No real customer, contact, hotel, price, contract, Google Sheet, ChannelHub, OTA, WordPress, deployment, or production data is part of this candidate.

## One-time production seed gate

Production does not permit demo seeding by default. The fictional dataset may be initialized only when all four conditions are true at the same time:

1. `APP_ENV=production`
2. `BOATOPS_DEMO_SITE_ENABLED=true`
3. `BOATOPS_DEMO_SITE_MODE=public_read_only`
4. `BOATOPS_DEMO_SITE_ALLOW_PRODUCTION_SEED=true`

Set a private `BOATOPS_DEMO_TOKEN` of at least 24 characters, run only `php artisan db:seed --class=DemoSiteSeeder --force`, and then immediately return `BOATOPS_DEMO_SITE_ALLOW_PRODUCTION_SEED=false` before serving traffic. The token is hashed for internal demo actors and must never be sent to a browser. Re-running this dedicated seeder is idempotent for its named fictional records, but it is not a general production-data migration mechanism.

## Local acceptance evidence (2026-08-08)

This remains a local candidate and is not a deployment or release claim.

- PHPUnit: 121 tests / 1,367 assertions passed; Pint passed.
- Contract suite: 7 Inventory endpoints, 26 Operations path templates / 27 Operations, 9 event schemas, and 5 event fixtures passed.
- Production frontend build, strict Composer validation, Composer audit, and npm audit passed; npm reported 0 vulnerabilities.
- An isolated SQLite `migrate:fresh -> migrate:rollback -> migrate` round trip passed and the disposable database was removed.
- A real `production + public_read_only` local HTTP run returned 200 for all three GET pages, `noindex/no-store` headers, and 405 for a Demo POST.
- Browser QA passed at desktop and 390px widths for `/demo`, `/demo/calendar`, and `/demo/slots`: no horizontal overflow, no POST forms, no browser warnings/errors, and no password fields or browser credentials.
- Clicking an available GET simulation changed no allocation, HOLD, booking, audit, cash, stock, or organization revision count. The selected boat showed the simulated choice and compatibility conflicts; the other boat projection remained unchanged.
