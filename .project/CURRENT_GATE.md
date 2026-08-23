# BoatOps Current Guardrail

Updated: 2026-08-23 Asia/Bangkok

This file records only the immediate boundary for the next real task. It is not a phase engine or second task system.

## Current decision

```text
PRIMARY_GOAL = REAL_OPERATOR_USE
PRODUCTION_SURFACE = https://boatops.ayany.com/
PRODUCTION_SHA = 4bdd541cb739b257153dc9fb45a7eb7ba97bd40e
PRODUCTION_DEPLOYMENT = VERIFIED_LIVE
NEXT_OPERATION = WAIT_FOR_NEXT_GENUINE_OPERATION
ENGINEERING_DEFAULT = STOP_UNLESS_NEXT_REAL_OPERATION_NEEDS_IT
CURRENT_SAFETY_EXCEPTION = ISSUE_4_MAIN_PROTECTION
DSH_MISSION_AUTHORITY = OWNING_GITHUB_ISSUE_LABELS
```

REAL-OPS-001 / Issue #41 is complete and accepted. Quick Paste is verified in production, including the unknown-fact guard. No real Inquiry has been created yet.

Issue #4 is the only current repository-safety exception: `main` protection is still not enabled. It does not authorize product, schema, deployment, or production-data changes.

## Permanent question

Before adding anything:

> **如果不增加这个东西，下一个真实任务会完成不了吗？**

If no, do not build it now.

## Allowed now

- complete the bounded minimum `main` protection in Issue #4 and verify the live GitHub settings;
- wait for the next genuine boat operation rather than inventing production data;
- when a genuine operation arrives, run it through the existing production Operator surface;
- capture concrete missing facts, friction, safety blockers, or observability gaps from real use;
- fix one small bounded blocker that real use proves necessary;
- use an owning GitHub Issue with `dsh:ready / dsh:running / dsh:done / dsh:blocked` when DSH execution is required.

An open Issue without a DSH execution label is not automatically the current executable Mission.

## Not justified now

- speculative BoatOps features;
- permanent TEST/staging environment;
- ERP / CRM / finance / reporting expansion;
- Google Sheet importer or historical-order migration without a proven operational need;
- second workflow engine, second task system, or Mission database;
- project-local watcher or scheduler;
- broad Admin UI;
- API / OTA / ChannelHub work without a real consumer;
- governance expansion unrelated to a proven safety or operational gap.

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
ISSUE #4 MINIMUM MAIN PROTECTION
-> verify live GitHub settings
-> close the repository-safety gap
-> STOP DEVELOPMENT
-> wait for the next genuine operation
-> use existing Dashboard and operational records
-> observe what staff cannot see or execute reliably
-> record the smallest proven gap
-> create one bounded Mission only if a change is required
-> validate / deploy when explicitly authorized
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
Git history / PR / Issue / CI / deployment receipt = implementation and historical evidence
DSH labels = handoff interface only
```
