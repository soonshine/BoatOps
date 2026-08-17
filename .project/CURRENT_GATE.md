# BoatOps Current Guardrail

Updated: 2026-08-17 10:28 Asia/Bangkok

This file is intentionally small.

It is **not** a phase engine, readiness matrix, or release bureaucracy. It records only the immediate operating boundary for the next real task.

## Current decision

```text
PRIMARY_GOAL = REAL_OPERATION_USE
PRODUCTION_SURFACE = https://boatops.ayany.com/
PERMANENT_TEST_GATE = NOT_REQUIRED
DEVELOPMENT_MODEL = REAL_USE_LOOP
NEW_FEATURE_DEFAULT = STOP_UNLESS_NEXT_REAL_OPERATION_NEEDS_IT
```

## Permanent question

Before adding anything:

> **如果不增加这个东西，下一个真实任务会完成不了吗？**

If no, do not build it now.

## Allowed now

- make the smallest change needed to put the existing BoatOps vertical slice into real operation;
- configure and deploy `boatops.ayany.com` from an exact Git SHA;
- make production deployment safe enough for the actual change: validation, migration check, backup/recovery, smoke check;
- fix a blocker found during real operation;
- fix observed operator pain;
- improve SSOT or observability when real operations cannot be trusted or understood without it;
- fix universal safety defects;
- use temporary isolated tests only when a specific risk needs them.

## Not justified by default

- maintaining a permanent TEST/staging environment;
- CAL-UX-004 or another feature sequence simply because the previous numbered item is finished;
- new ERP modules;
- generic CRM / finance / reporting platforms;
- second workflow engine or second task system;
- broad Admin UI before repeated configuration pain exists;
- API / OTA / ChannelHub work without a real consumer;
- dashboards that do not help current operations;
- governance-only PRs, readiness matrices, or work packages for routine progress;
- architecture abstraction for hypothetical future users.

## Hard safety boundaries

The simplified model does not permit unsafe production shortcuts.

Stop if a task would require:

- committing or exposing secrets / credentials / PII / production backups;
- destructive synthetic testing against production data;
- unexplained irreversible production data mutation;
- bypassing organization isolation;
- bypassing transactional Boat occupancy conflict checks;
- manual production source edits that are not represented in Git;
- deploying code whose exact SHA cannot be identified;
- proceeding after a failed relevant safety check without resolving or explicitly containing the risk.

## Current next action

```text
MAKE https://boatops.ayany.com/ THE REAL BOATOPS OPERATING SURFACE
```

Minimum path:

```text
current usable BoatOps code
-> verify only deployment-critical configuration
-> backup / recovery path
-> deploy exact Git SHA
-> health + login + core workflow smoke
-> start real use
-> observe actual pain
-> next smallest change
```

Do not add unrelated features before this loop is running.

## Current SSOT boundary

```text
GitHub = code + durable project state
production PostgreSQL = real operational data after cutover
boatops.ayany.com = real operator surface
Git history = historical TEST / Gate evidence
```

Old TEST evidence remains valid historical evidence, but it is no longer a mandatory step or active project gate.
