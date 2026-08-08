# BoatOps Project Charter

Status: `ACTIVE`

Charter version: `1.0`

Effective date: `2026-08-08` (Asia/Bangkok)

## 1. Product mission

BoatOps is the inventory and internal-operations system for the Plan A and Plan B boats. The immediate product goal is the smallest operator workflow that staff can actually use:

`INQUIRY -> HOLD -> CONFIRMED -> AMEND / CANCEL`

`BLOCKED` is an independent inventory state used to close a boat or time range.

The first usable internal release needs an authenticated operator calendar, reliable whole-boat conflict control, and the minimum order-state actions. It does not need a large administrative dashboard.

## 2. Current product boundary

In scope for the next product gate:

- operator login;
- 7-day and 30-day whole-boat calendars;
- inquiry creation;
- HOLD with explicit expiry;
- confirm;
- change time or boat;
- cancel;
- block and release block;
- view order and audit history;
- preset and custom slot definitions after real Plan A / Plan B rules are frozen.

Deferred until a later approved gate:

- full finance completion;
- full warehouse completion;
- Google Sheet migration or bidirectional synchronization;
- ChannelHub;
- OTA distribution;
- payments, refunds, formal accounting, and tax reporting;
- formal open-source release, Tag, or GitHub Release.

## 3. Non-negotiable architecture rules

1. BoatOps is the production inventory source of truth.
2. Production conflict decisions are adjudicated transactionally by PostgreSQL. A UI, cache, spreadsheet, ChannelHub, or agent may not overrule the database result.
3. Inventory is whole-boat plus occupied time interval. Service time, buffer-before, and buffer-after are distinct facts.
4. HOLD, CONFIRMED booking, and BLOCK all create authoritative occupied intervals. Cancel, release, expiry, and amendment must change authority atomically and leave an audit trail.
5. Calendar projections and simulations are read models. Final HOLD and confirm commands must always re-adjudicate availability.
6. Custom slots are first-class definitions or date-specific instances; they are not free-text exceptions hidden in an order note.
7. ChannelHub is a separate system. It may use a versioned BoatOps API but must never write the BoatOps database directly.

## 4. Public Demo boundary

The public Demo is a disposable product demonstration, not a production operator surface.

- It contains only synthetic organizations, boats, bookings, finance, and stock data.
- It must use a physically isolated dataset and must never share a production BoatOps database.
- Its served runtime is read-only: public HTTP requests may not create or update application, authentication, session, cache, audit, queue, finance, stock, or inventory rows.
- In `public_read_only` mode, the application API is closed; only explicitly approved public GET pages and health/static pages may be reachable.
- Demo seeders may modify only the explicitly resolved fictional organization.
- The Demo must never accept imported Google Sheet data, real customer data, real prices, real contracts, or real operations data.
- A deployed Demo remains `CANDIDATE / NOT_RELEASED` unless a later gate explicitly changes that status.

## 5. System boundaries

- BoatOps owns boats, occupied intervals, slot definitions, HOLDs, confirmed bookings, blocks, amendments, cancellations, and inventory revisions.
- ChannelHub owns channel adapters, channel credentials, publishing jobs, external mappings, retries, and channel-facing availability caches.
- ChannelHub calls BoatOps through an authenticated and versioned interface. It does not share tables or credentials with BoatOps.
- Finance and stock may reference BoatOps boats, trips, and bookings, but they do not decide whether a time slot is sellable.

## 6. Security and data handling

- Credentials are supplied through an approved secret source or environment reference; plaintext credentials are forbidden in Git, task prompts, reports, screenshots, logs, and configuration files.
- A credential that appears in a task log is treated as exposed and must be rotated, revoked, cleaned locally, and checked against Git history.
- Demo and test credentials must be synthetic and must not grant access to any real service or dataset.
- No agent may inspect browser cookies, password stores, local storage, or session stores.

## 7. Sources of truth

In descending order:

1. explicit user decision;
2. this Charter;
3. `.project/CURRENT_STATE.yaml`;
4. `.project/CURRENT_GATE.md` and `.project/REVIEW_QUEUE.md`;
5. reviewed Git commits and reproducible evidence;
6. agent reports and chat history.

Chat history and an executor's self-report are never sufficient proof of code, deployment, data isolation, or test success.

## 8. Project roles

- Owner: product goal, real business rules, and final launch authority.
- ChatGPT reviewer: architecture, scope, code review, gate decision, and the next bounded instruction.
- Hermes: implementation, tests, progress, and evidence.
- Claude Code: optional builder delegated by Hermes; its availability must not block the project.
- Codex: optional independent milestone review when requested and available.

The executor cannot approve its own gate. The reviewer cannot treat an executor report as independent evidence.

## 9. Gate and release discipline

- Merge, deployment, Tag, Release, data migration, and production enablement are separate authorizations.
- Passing tests does not itself authorize merge or deployment.
- A gate is complete only when every stated acceptance criterion has reproducible evidence and the reviewer records `APPROVED`.
- If `merge_authorized` or `deployment_authorized` is false in `CURRENT_STATE.yaml`, no agent may perform that action.
- Work must stop on scope drift, a failed safety invariant, an unexplained data mutation, or evidence that cannot be reproduced.
