# BoatOps Current Guardrail

Updated: 2026-08-19 Asia/Bangkok

This file is intentionally small. It records only the immediate boundary for the next real task; it is not a phase engine or second task system.

## Current decision

```text
PRIMARY_GOAL = REAL_OPERATOR_USE
PRODUCTION_SURFACE = https://boatops.ayany.com/
PRODUCTION_SHA = 17dc0adf8209d58cfa3912b91ed9c541f856fb41
PRODUCTION_DEPLOYMENT = VERIFIED_LIVE
DEVELOPMENT_MODEL = REAL_USE_LOOP
NEXT_PROJECT_OBJECTIVE = EXECUTE_FIRST_REAL_OPERATION_AND_OBSERVE_FEEDBACK
ACTIVE_DSH_MISSION = NONE
NEW_FEATURE_DEFAULT = STOP_UNLESS_NEXT_REAL_OPERATION_NEEDS_IT
```

The prior dashboard deployment Mission is complete; its durable task record is Issue #39 with `dsh:done`.

## Permanent question

Before adding anything:

> **如果不增加这个东西，下一个真实任务会完成不了吗？**

If no, do not build it now.

## Allowed now

- run the next real boat operation through the production Operator Dashboard;
- capture concrete missing facts, friction, safety blockers, or observability gaps from real use;
- fix a small bounded blocker that real use proves is necessary;
- prepare a bounded GitHub Issue Mission with explicit `GOAL / ACCEPTANCE / ALLOWED / STOP-ESCALATE`;
- arm that Mission for DSH only by adding `dsh:ready`.

An open Issue without a DSH execution label is not automatically the current executable Mission.

## Not justified now

- speculative BoatOps features;
- permanent TEST/staging environment;
- ERP / CRM / finance / reporting expansion;
- second workflow engine, second task system, or Mission database;
- project-local watcher or scheduler;
- broad Admin UI;
- API / OTA / ChannelHub work without a real consumer;
- governance-only expansion unrelated to the next real operation.

## Hard safety boundaries

Stop if the task would require:

- exposing or committing secrets, credentials, PII, or production backups;
- destructive synthetic testing against production data;
- unexplained irreversible production data mutation;
- bypassing organization isolation or transactional Boat occupancy checks;
- manual production source edits not represented in Git;
- deploying an unidentified or different Git SHA;
- changing product intent, Acceptance Criteria, or Mission scope without Control Plane approval;
- claiming runtime success without evidence.

## Current next action

```text
NEXT REAL OPERATION
-> use existing Dashboard and operational records
-> observe what staff cannot see or execute reliably
-> record the smallest proven gap
-> create one bounded Mission if a change is required
-> validate
-> deploy only when explicitly authorized
-> observe again
```

## DSH handoff pointer

```text
Entrypoint: AGENTS.md
Project authority: .project/PROJECT_CHARTER.md + .project/CURRENT_STATE.yaml + this file
Mission authority: owning GitHub Issue
Verification: task-specific checks + scripts/check.sh where code changes exist
Durable writeback: same Issue + commit / PR / tests / deployment evidence
Execution labels: dsh:ready / dsh:running / dsh:done / dsh:blocked
```

## Current SSOT boundary

```text
GitHub = code + durable project/task state
production PostgreSQL = real operational data
boatops.ayany.com = real operator surface
Git history / PR / CI / deployment receipt = implementation and deployment evidence
DSH labels = handoff interface only
```
