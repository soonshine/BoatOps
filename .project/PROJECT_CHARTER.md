# BoatOps Project Charter

Status: `ACTIVE`

Charter version: `3.1`

Effective: `2026-08-27` after merge to `main`

## 1. Mission

> **BoatOps exists to make real boat operations reliable, visible, and less dependent on human memory.**

第一目标不是建设完整船务 ERP，而是让下一笔真实船务任务能够更安全、更清楚地完成。

永久优先级：

> **Safety / Operational Truth > Time-to-Real-Use > Feature Completeness**

## 2. Permanent development model

BoatOps 的默认顺序只有这一条：

```text
FIRST PRINCIPLES
-> define the real operational problem
-> MINIMUM IMPLEMENTATION PATH
-> choose the shortest path that can solve it
-> VERTICAL SLICE
-> complete the real end-to-end workflow
-> TIME TO REAL USE
-> put it into real use quickly
-> FEEDBACK LOOP
-> let real use expose the next problem
-> SINGLE SOURCE OF TRUTH
-> keep operational facts trustworthy
-> OBSERVABILITY
-> know what is happening now
-> PROGRESSIVE COMPLEXITY
-> add complexity only when proven necessary
```

Every proposed feature, abstraction, service, workflow, environment, dashboard, automation, or governance layer must first answer:

> **如果不增加这个东西，下一个真实任务会完成不了吗？**

If the answer is no, it is not current priority by default.

## 3. First-principles operational questions

BoatOps should progressively make these questions easy to answer:

1. 今天有哪些订单要执行？
2. 每个订单使用哪条船？
3. 谁负责？
4. 客人在哪里、几点接？
5. 几点出航？
6. 航线是什么？
7. 有多少客人？
8. 需要准备什么？
9. 船当前是否可用？
10. 是否有维修、天气或人员问题？
11. 当前订单处于什么状态？
12. 有异常时谁需要处理？
13. 完成后留下什么记录？

A feature that does not materially improve one of these questions needs explicit justification.

## 4. Minimum real-use vertical slice

The primary product surface is **Operator Web**.

The minimum operational loop is:

```text
Order / Inquiry
-> assign Boat
-> assign responsible people
-> confirm pickup / pier / departure / route / passenger count
-> prepare required items
-> pickup / boarding
-> depart
-> return
-> complete
-> retain audit / incident evidence
```

Inventory remains whole-vessel interval authority:

```text
Organization + Boat + Occupied Interval
```

Existing HOLD / Booking / BLOCK / Trip primitives are implementation tools for this loop, not reasons to expand product scope by themselves.

## 5. Architecture

Keep the application architecture deliberately small:

```text
Operator Web
    -> Shared Application Actions
        -> PostgreSQL
        -> Audit / Idempotency / Outbox where already required
```

When a proven real-use problem benefits from language understanding, BoatOps may use a bounded external AI inference path:

```text
Operator Web
    -> Application AI Parsing / Assistance Service
        -> External Model Provider
        -> validated suggestions only

Operator review / confirmation
    -> existing Shared Application Actions
        -> PostgreSQL
```

AI boundary:

- AI interprets, extracts, summarizes, or suggests; it is not operational authority.
- AI never directly writes PostgreSQL, reserves Boat inventory, confirms a booking, changes Trip state, assigns people, prices an order, or submits an operational form.
- Provider responses must be validated against an explicit allowlist/schema before entering the application flow.
- Entity names suggested by AI must be resolved deterministically against organization-scoped BoatOps truth; AI must not invent database IDs.
- Unknown or unsupported facts remain empty / `null`; absence of evidence is not permission to infer a business fact.
- Existing operator-entered facts are not silently overwritten by AI suggestions.
- AI/provider failure must degrade to the existing manual workflow; BoatOps operations must not depend on provider availability.
- Provider credentials remain server-side. Customer data sent externally must be minimized to the current task, and routine logs must not retain raw customer PII.
- A first provider such as DeepSeek is an implementation choice, not a permanent product dependency. Add a provider abstraction only when a second proven consumer/provider makes it useful.

General architecture rules:

- Web is the primary operating surface.
- Controllers remain thin transport / authorization adapters.
- Web, APIs, jobs, and agents must reuse the same business actions when they exist.
- PostgreSQL is the operational data authority once BoatOps is used for the real workflow.
- APIs, events, jobs, integrations, and AI inference are added only for a demonstrated consumer.
- Do not create a second workflow engine, second task system, second operational SSOT, general Agent platform, vector store, or AI Gateway unless real use proves it necessary.

## 6. Single production runtime

The intended real operating surface is:

```text
https://boatops.ayany.com/
```

BoatOps does **not** require a permanent TEST or staging environment as a routine development gate.

Default delivery path:

```text
small bounded change
-> automated/local validation
-> backup / migration safety check when relevant
-> deploy to boatops.ayany.com
-> smoke check
-> real operational use
-> observe pain / error
-> next minimum change
```

A temporary isolated test database or synthetic runtime may still be used when a specific risky change requires it, but it is a tool, not a mandatory project phase.

Direct production development never means editing production source manually. GitHub remains the code and durable project-state authority.

## 7. Sources of truth

| Fact | Authority |
| --- | --- |
| Mission, scope, principles, invariants | `.project/PROJECT_CHARTER.md` |
| Current project/runtime state | `.project/CURRENT_STATE.yaml` |
| Immediate allowed / forbidden / next action | `.project/CURRENT_GATE.md` |
| Code and tests | Git commit + reproducible checks |
| Deployed code identity | production deployment receipt / exact Git SHA |
| Real operational data | production PostgreSQL |
| Historical decisions and prior states | Git / PR history |

Chat history, Worker self-report, spreadsheets, LINE messages, external AI output, and employee memory are not competing operational authorities.

## 8. Observability before analytics

BoatOps must first make current operations visible.

Minimum useful observability:

- today's orders and current status;
- unassigned Boat / Captain / Crew / Driver when relevant;
- Boat unavailable / blocked / maintenance condition;
- pickup, boarding, departed, returned, completed status;
- current incidents / blockers;
- incomplete preparation tasks when they become necessary for execution;
- who changed an operational fact, when, and what changed;
- deployed source SHA, health, scheduler, backup and rollback status.

Reuse existing audit, status, health, revision, idempotency and database constraints before building a reporting platform.

## 9. Safety invariants

Safety is a boundary, not a separate product bureaucracy.

Permanent invariants:

1. Cross-organization data is neither visible nor mutable.
2. Boat occupancy conflicts are transactionally adjudicated in PostgreSQL.
3. HOLD / Confirm / Amend / Cancel / BLOCK decisions remain auditable and fail closed on conflict.
4. Booking or Trip completion must not release physical inventory before the required occupied interval ends.
5. Service time, buffers, occupied interval, Trip lifecycle and inventory authority remain distinct facts.
6. Real credentials, secrets, customer PII and production backups never enter public Git, fixtures, screenshots or reports.
7. Before a risky production mutation, there must be a proportionate recovery path: backup, rollback, reversible migration, or an explicit reason it is unnecessary.
8. External AI output is untrusted input until BoatOps validation and human/application confirmation; it cannot independently mutate operational truth.

Do not create a new Gate document, readiness matrix, or approval layer unless a real risk cannot be controlled without it.

## 10. Progressive complexity

Add only after real use proves the need. Examples:

- capacity fields only when real vessel-limit handling needs them;
- detailed preparation task models only when checklist omissions repeatedly cause execution errors;
- maintenance module only when Boat availability cannot be managed reliably without structured maintenance state;
- Admin UI only when repeated configuration work is error-prone;
- Finance / fuel / stock / expense only when they block or materially degrade real operations;
- API / ChannelHub / OTA only when a real consumer exists;
- AI-assisted extraction only when real operator input proves deterministic parsing unreliable;
- AI Agent autonomy only after the underlying operational state and human responsibility are clear.

Current default non-goals:

- complete ERP;
- SPA rewrite;
- generic workflow engine;
- generic CRM / accounting / reporting platform;
- general AI Agent / AI Gateway / vector-memory platform;
- multi-environment release bureaucracy;
- features justified only by future possibility.

## 11. Product portability

BoatOps remains organization-scoped and reusable.

Ayany, Plan C, vessel names, staff identities, schedules, prices, AI provider names and operating rules are configuration / operational facts, not hard-coded product assumptions.

Reusability must not delay real use. Build the smallest organization-scoped implementation that works now; generalize only when a second real case proves the need.

## 12. Definition of progress

Progress is not the number of features, documents, PRs, tables, services, or agents.

BoatOps progresses when a real operation becomes:

- easier to execute;
- harder to execute incorrectly;
- easier to observe;
- easier to recover;
- less dependent on one person's memory.

The default next step is always the smallest change that improves the next real operation.