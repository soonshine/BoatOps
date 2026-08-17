# BoatOps Current Guardrail

Updated: 2026-08-17 11:05 Asia/Bangkok

This file is intentionally small. It is not a phase engine or readiness bureaucracy; it records only the immediate boundary for the next real task.

## Current decision

```text
PRIMARY_GOAL = REAL_OPERATION_USE
PRODUCTION_SURFACE = https://boatops.ayany.com/
PERMANENT_TEST_GATE = NOT_REQUIRED
DEVELOPMENT_MODEL = REAL_USE_LOOP
NEW_FEATURE_DEFAULT = STOP_UNLESS_NEXT_REAL_OPERATION_NEEDS_IT
PRODUCTION_CANDIDATE = cf49e11376eba356eeff855856d09d11637780c9
CANDIDATE_CI = PASS
PRODUCTION_DEPLOYMENT = PENDING_RUNTIME_EXECUTION
DEPLOY_TASK = PROD-CUTOVER-001 / Issue #36
```

## Permanent question

Before adding anything:

> **如果不增加这个东西，下一个真实任务会完成不了吗？**

If no, do not build it now.

## Allowed now

Only work required to complete the current production cutover or a proven safety blocker:

- create and verify the production PostgreSQL backup;
- verify the server-local production `.env` without exposing secrets;
- deploy the exact candidate SHA above using `deploy/scripts/deploy-production.sh`;
- verify queue, scheduler, `/up`, root redirect, login boundary, and authenticated Operator access;
- verify Today Operations, Calendar, and Inquiry create/show;
- fix only a concrete blocker discovered during this cutover.

## Not justified now

- new BoatOps product features;
- permanent TEST/staging environment;
- CAL-UX-004 or another numbered feature sequence;
- ERP / CRM / finance / reporting expansion;
- second workflow engine or second task system;
- broad Admin UI;
- API / OTA / ChannelHub work without a real consumer;
- dashboards unrelated to current operations;
- governance-only work packages or readiness matrices.

## Hard safety boundaries

Stop if the task would require:

- exposing or committing secrets, credentials, PII, or production backups;
- destructive synthetic testing against production data;
- unexplained irreversible production data mutation;
- bypassing organization isolation or transactional Boat occupancy checks;
- manual production source edits not represented in Git;
- deploying an unidentified or different Git SHA;
- claiming LIVE without runtime evidence.

## Current next action

```text
PROD-CUTOVER-001
backup production PostgreSQL
-> verify production env
-> deploy cf49e11376eba356eeff855856d09d11637780c9
-> smoke
-> authenticated Operator check
-> record deployed SHA and runtime evidence
-> mark LIVE only after proof
```

No unrelated feature work before this loop is complete.

## Current SSOT boundary

```text
GitHub = code + durable project state
production PostgreSQL = real operational data after cutover
boatops.ayany.com = real operator surface
Git history = historical TEST / Gate evidence
```

Old TEST evidence remains historical evidence only; it is not a mandatory step or active gate.
