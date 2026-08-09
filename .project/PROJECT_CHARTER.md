# BoatOps Project Charter

Status: `ACTIVE`

Charter version: `1.1`

Effective date: `2026-08-09` (Asia/Bangkok)

## 1. Product mission

BoatOps is a reusable, self-hostable vessel inventory and operations platform for charter, yacht, speedboat, excursion, and local-activity operators.

BoatOps is **not an Ayany-specific product**. Ayany is not hard-coded as a tenant, vessel owner, or operator. The current two-vessel Plan A / Plan B scenario is a reference operating scenario used to build and validate the product; vessel ownership, operating rights, schedules, prices, buffers, and other commercial rules are deployment-specific data and must not be inferred as Ayany facts.

The core operational workflow is:

`INQUIRY -> HOLD -> CONFIRMED -> AMEND / CANCEL`

`BLOCKED` is an independent inventory state used to close a vessel or time range.

BoatOps should remain usable by another organization without requiring Ayany, WordPress, Google Sheet, ChannelHub, OTA integrations, or any Ayany-specific code path.

## 2. Product and tenancy boundary

BoatOps is organization-scoped. Organization boundaries are the primary tenant and authorization boundary for operational data.

Core product assumptions:

- vessels are operational resources; BoatOps must not assume they are owned by the deploying organization;
- ownership, operating rights, commercial representation, and sales-channel relationships are distinct business concepts and must remain deployment data unless a future gate explicitly models them;
- schedules, buffers, HOLD policy, weather policy, slot compatibility, prices, commissions, and operator identities are organization/deployment configuration, not global product constants;
- demo vessel names, demo times, synthetic operators, and synthetic prices are validation fixtures only;
- no Ayany-specific vessel ownership or operating rule may be derived from a demo fixture, hostname, or deployment location.

## 3. Current product capability baseline

The current reviewed source baseline includes:

- whole-vessel availability and occupied-interval adjudication;
- inquiry, HOLD, confirmation, amendment, cancellation, BLOCK, and release;
- authenticated Operator MVP for calendar, inquiry/HOLD, booking workflow, BLOCK management, and audit views;
- Trip execution foundations including crew/checklist and prepare/depart/return/complete state transitions;
- slot catalog, compatibility rules, schedule projections, and custom/date-specific slot foundations;
- Inventory Provider API and internal Operations API contracts;
- PostgreSQL conflict/concurrency validation in CI;
- operations-finance, stock, cash-posting, and reversal foundations that remain candidate capabilities until separately accepted for real operations.

A capability existing in source does not mean it is deployed, production-enabled, or accepted for real business data.

## 4. Non-negotiable architecture rules

1. BoatOps is the authoritative inventory and operations source of truth for an organization once that deployment is explicitly approved for production use.
2. Production conflict decisions are adjudicated transactionally by PostgreSQL. A UI, cache, spreadsheet, ChannelHub, or agent may not overrule the database result.
3. Inventory is whole-vessel plus occupied time interval. Service time, buffer-before, and buffer-after are distinct facts.
4. HOLD, CONFIRMED booking, and BLOCK all create authoritative occupied intervals. Cancel, release, expiry, and amendment must change authority atomically and leave an audit trail.
5. Calendar projections and simulations are read models. Final HOLD and confirm commands must always re-adjudicate availability.
6. Custom slots are first-class definitions or date-specific instances; they are not free-text exceptions hidden in an order note.
7. API, Operator UI, jobs, and future integrations must reuse the same application/domain actions for the same authoritative state mutation. No parallel inventory-rule path is allowed.
8. ChannelHub is a separate system. It may use a versioned BoatOps API but must never write the BoatOps database directly.
9. Finance and stock may reference vessels, trips, and bookings, but they do not decide whether a time slot is sellable.

## 5. Public Demo boundary

The public Demo is a disposable product demonstration, not a production operator surface.

- It contains only synthetic organizations, vessels, bookings, finance, stock, and operator identities.
- It must use a physically isolated dataset and must never share a production BoatOps database.
- Its public served runtime is read-only: public HTTP requests may not create or update application, authentication, session, cache, audit, queue, finance, stock, or inventory rows.
- In `public_read_only` mode, the application API is closed; only explicitly approved public GET pages and health/static pages may be reachable.
- A private fictional Operator runtime may exist for bounded validation if it uses the same isolated fictional dataset and is not publicly exposed.
- Demo seeders may modify only the explicitly resolved fictional organization.
- The Demo must never accept imported Google Sheet data, real customer data, real prices, real contracts, real financial records, or real operations data.
- A deployed Demo remains `DEMO / NOT_PRODUCTION / NOT_RELEASED` unless a later gate explicitly changes that status.

## 6. System boundaries

- BoatOps owns vessels/resources, occupied intervals, slot definitions, HOLDs, confirmed bookings, blocks, amendments, cancellations, trips, and inventory revisions.
- ChannelHub owns channel adapters, channel credentials, publishing jobs, external mappings, retries, and channel-facing availability caches.
- ChannelHub calls BoatOps through an authenticated and versioned interface. It does not share tables or credentials with BoatOps.
- WordPress is content/SEO only unless a later contract explicitly authorizes an integration; it is never inventory truth.
- Google Sheet may be a migration/reconciliation source during an authorized cutover, but it is not allowed to overrule BoatOps inventory once BoatOps becomes production truth.

## 7. Security and data handling

- Credentials are supplied through an approved secret source or environment reference; plaintext credentials are forbidden in Git, task prompts, reports, screenshots, logs, and configuration files.
- A credential that appears in a task log is treated as exposed and must be rotated, revoked, cleaned locally, and checked against Git history.
- Demo and test credentials must be synthetic and must not grant access to any real service or dataset.
- No agent may inspect browser cookies, password stores, local storage, or session stores.
- Public repository evidence must contain no customer PII, production backup, real contract, real quote, real financial data, or server secret.

## 8. Sources of truth

In descending order:

1. explicit Owner decision;
2. this Charter;
3. `.project/CURRENT_STATE.yaml`;
4. `.project/CURRENT_GATE.md` and `.project/REVIEW_QUEUE.md`;
5. reviewed Git commits and reproducible deployment/test evidence;
6. agent reports and chat history.

Chat history and an executor's self-report are never sufficient proof of code, deployment, data isolation, or test success. When an accepted deployment occurs outside GitHub, a later governance alignment must record the accepted non-secret evidence in Git so the repository can again become the authoritative project ledger.

## 9. Project roles

- Owner: product goal, real business rules, merge/deployment/data/launch authority.
- ChatGPT reviewer: architecture, scope, review, blocker classification, gate decision, and the next bounded instruction.
- Hermes: bounded implementation/execution, tests, progress, and evidence.
- Claude Code: optional builder delegated by Hermes; its availability must not block the project.
- Codex: optional independent milestone reviewer when explicitly requested; it does not replace the Owner or the primary project reviewer.

The executor cannot approve its own gate. The reviewer cannot treat an executor report as independent evidence.

## 10. Gate and release discipline

- Merge, deployment, Tag, GitHub Release, data migration, and production enablement are separate authorizations.
- Passing tests does not itself authorize merge or deployment.
- A gate is complete only when every stated acceptance criterion has reproducible evidence and the reviewer records `APPROVED` / `COMPLETE`.
- If an authorization flag is false in `CURRENT_STATE.yaml`, no agent may perform that action unless the Owner explicitly supersedes it.
- Work must stop on scope drift, a failed safety invariant, an unexplained data mutation, or evidence that cannot be reproduced.
- Demo deployment success must never be reclassified as production enablement.
- Product gate identifiers (`G*`), deployment/validation identifiers (`D*`), and future semantic version Tags/Releases are separate namespaces and must not be conflated.
