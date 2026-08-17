# BoatOps Agent Rules

Status: `MANDATORY`

Every agent that plans, implements, reviews, deploys, or reports on BoatOps must follow this file.

## 1. Required startup sequence

Before doing work, read in this order:

1. `.project/CURRENT_STATE.yaml`
2. `.project/CURRENT_GATE.md`
3. the exact task / code / diff / runtime evidence in the assigned scope

Read `.project/PROJECT_CHARTER.md` whenever product scope, architecture, or an invariant is relevant.

Do not require a Worker to reconstruct project history from old conversations. GitHub + current task evidence must be sufficient.

## 2. Permanent decision filter

Before adding any feature, abstraction, service, environment, agent, dashboard, workflow, field group, or governance layer, answer:

> **如果不增加这个东西，下一个真实任务会完成不了吗？**

If not, do not add it by default.

Allowed reasons for current work:

- a real operation cannot be completed;
- a real operation is likely to be completed incorrectly;
- repeated real-use friction is wasting meaningful time;
- current operational truth cannot be trusted or observed;
- a safety defect threatens real data or execution.

Unproven future convenience is not enough.

## 3. Routine execution model

Routine BoatOps progress follows:

```text
real operational task
-> identify the actual blocker or pain
-> smallest bounded change
-> validate
-> deploy to boatops.ayany.com when authorized by the current task
-> smoke check
-> real use
-> observe result
-> record durable truth
-> next minimum change
```

Do not invent phases, work packages, readiness matrices, governance PRs, or release trains for routine work.

## 4. Single-runtime model

The intended real operating surface is:

`https://boatops.ayany.com/`

A permanent TEST/staging environment is not a required gate.

Temporary isolated databases, synthetic tests, local runtimes, or one-off validation environments may be used when a specific risk justifies them. They are implementation tools only.

Never edit production source manually as a substitute for Git-based deployment.

## 5. Source-of-truth contract

- GitHub owns code and durable project state.
- Production PostgreSQL owns real operational data.
- The deployed Git SHA identifies running application code.
- Chat, Worker memory, LINE, spreadsheets, and verbal updates are not competing SSOTs.
- Do not create a second task system or second operational truth store without a proven blocker.

## 6. Product architecture

Keep architecture small:

```text
Operator Web
-> Shared Application Actions
-> PostgreSQL
-> existing Audit / Idempotency / Outbox only where required
```

Rules:

- Web-first.
- Thin controllers.
- Reuse business actions across Web/API/jobs/agents.
- Add public APIs or integrations only for a real consumer.
- Prefer structured operational fields and stable IDs over important facts hidden in notes/chat.
- Prefer explicit small state machines over free-text operational status.

## 7. Production safety

Direct real-use development does not remove safety controls.

Before a production-affecting change, use controls proportional to the risk:

- run relevant automated checks;
- inspect migration impact when schema changes;
- back up production data when meaningful rollback requires it;
- ensure a rollback/recovery path exists;
- deploy an exact Git SHA;
- run a focused smoke check;
- verify no unexplained data mutation occurred.

Hard stops:

- never expose or commit secrets, credentials, customer PII, production backups, or private contracts;
- never run destructive synthetic tests against production data;
- never perform an unexplained irreversible data mutation;
- never bypass organization isolation or transactional inventory authority;
- stop on failed safety checks, unexplained mutation, scope drift, or unreproducible runtime evidence.

## 8. Operational invariants

- PostgreSQL transaction results are authoritative for Boat occupancy conflicts.
- HOLD / Confirm / Amend / Cancel / release / expiry / BLOCK remain atomic and auditable.
- Calendar/availability projections never overrule transactional conflict checks.
- Booking or Trip completion does not release physical inventory before the required occupied interval ends.
- Cross-organization data is neither visible nor mutable.
- UI simulations, spreadsheets, cached availability, ChannelHub, or AI suggestions cannot independently reserve inventory.

## 9. Observability contract

Prefer making operations observable before adding management/reporting features.

A useful change should, where relevant, make it easier to know:

- what must happen today;
- who/which Boat is assigned or missing;
- current execution status;
- current blocker/incident;
- what changed and who changed it;
- which Git SHA is deployed;
- application health, scheduler, backup and rollback state.

## 10. Git discipline

- Start from current `main` unless the task explicitly requires another base.
- Use a dedicated branch for bounded work.
- Keep commits single-purpose and reviewable.
- Preserve unrelated user changes.
- Do not rewrite shared history.
- Record exact changed files, validation commands/results, final commit SHA, deploy status, and anything not verified.

An executor may report that its bounded task passed its evidence checks. It may not invent business facts or silently expand scope.

## 11. Progressive complexity

When a real problem appears, prefer modifying the smallest existing concept before introducing a new subsystem.

Examples:

- use an existing status before creating a workflow engine;
- use audit before creating analytics;
- use a simple checklist before creating a task platform;
- use configuration before creating an Admin UI;
- use current application actions before creating an API-only duplicate path;
- use one production runtime before maintaining multiple permanent environments.

The correct architecture is the smallest architecture that can reliably execute the next real operation.