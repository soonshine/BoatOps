# BoatOps Demo Site: Public Read-Only Candidate

Status: `DEPLOYED_CANDIDATE / PUBLIC_READ_ONLY / NOT_MERGED / NOT_TAGGED / NOT_RELEASED`

This document describes the deployed Gate D0 public read-only candidate. Deployment does not make BoatOps the production inventory master and does not authorize real-data migration.

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

- PHPUnit: 121 tests / 1,367 assertions passed; Pint passed.
- Contract suite: 7 Inventory endpoints, 26 Operations path templates / 27 Operations, 9 event schemas, and 5 event fixtures passed.
- Production frontend build, strict Composer validation, Composer audit, and npm audit passed; npm reported 0 vulnerabilities.
- An isolated SQLite `migrate:fresh -> migrate:rollback -> migrate` round trip passed and the disposable database was removed.
- A real `production + public_read_only` local HTTP run returned 200 for all three GET pages, `noindex/no-store` headers, and 405 for a Demo POST.
- Browser QA passed at desktop and 390px widths for `/demo`, `/demo/calendar`, and `/demo/slots`: no horizontal overflow, no POST forms, no browser warnings/errors, and no password fields or browser credentials.
- Clicking an available GET simulation changed no allocation, HOLD, booking, audit, cash, stock, or organization revision count. The selected boat showed the simulated choice and compatibility conflicts; the other boat projection remained unchanged.

## Public deployment evidence (2026-08-08)

The exact reviewed artifact was deployed to [https://boatops.ayany.com/demo](https://boatops.ayany.com/demo) with an isolated fictional SQLite database. It does not connect to the previous PostgreSQL dataset, Google Sheet, ChannelHub, OTA, WordPress, or real operations data.

- Release ID: `20260808T054205Z`
- Git commit: `011cd81c7fd7907086178db735dbebc28abe7b61`
- Source tree SHA-256: `7ebe669cb39c0a529f106e6d60d637227a831c0b704fb4c4413f2841bcb81458`
- Archive SHA-256: `140a1e6c56a05175af2524467f2099f274834232aa89785a62cec3e1f839c97a`
- Rollback: the pre-deployment candidate remains intact and was proven by an automatic rollback before the successful switch.
- Public HTTP: `/up`, `/`, `/demo`, `/demo/calendar`, and `/demo/slots` returned 200; a POST to `/demo/calendar` returned 405.
- Response boundary: `noindex, nofollow, noarchive` and `no-store` were verified from outside the server; no public page contained a POST form, password field, browser credential, or `LOCAL ONLY` marker.
- Data boundary: the `Public Demo Reader` retained exactly `operations.finance.read` and `operations.schedule.read`; the production seed flag remained false after the one-time fictional seed command.
- Browser acceptance: all three public pages rendered correctly at desktop width with no horizontal overflow or console errors. The exact source artifact had already passed 390px QA before deployment.
- Interaction acceptance: a real browser click on an available GET simulation rendered `SIMULATED_SELECTED` and the GET-preview notice; the SQLite SHA-256 remained unchanged before and after the click.
- Runtime acceptance: Nginx configuration passed, PHP-FPM and the queue worker were active, and the worker resolved to the deployed release.

The first switch attempt returned 500 during live acceptance because the privileged preview process had created file-cache entries with the wrong owner. The deployment guard automatically restored the previous candidate. Runtime ownership was corrected, the preview was repeated under the production web user, and the second atomic switch passed. No new application error was recorded during the final external GET and browser acceptance runs.

This remains a candidate deployment. It is not merged to `main`, has no release Tag, has no formal open-source license grant, does not accept real bookings, and does not freeze the real Plan A / Plan B operating times.
