# BoatOps Current Guardrail

Updated: 2026-09-19 Asia/Bangkok

This file records only the immediate boundary for the next real task. It is not a phase engine or second task system.

## Current decision

```text
PRIMARY_GOAL = REAL_OPERATOR_USE
PRODUCTION_SURFACE = https://boatops.ayany.com/
PRODUCTION_SHA = 5cf54d2faff568962fc4c780e4b141e980afce1a
PRODUCTION_DEPLOYMENT = VERIFIED_LIVE
PRODUCTION_CUTOVER = VERIFIED
CURRENT_PHASE = PRODUCTION_LIVE_AWAITING_FIRST_REAL_OPERATION
NEXT_OPERATION = WAIT_FOR_NEXT_GENUINE_OPERATION
NEXT_OPERATIONAL_OBJECTIVE = EXECUTE_FIRST_REAL_OPERATION_AND_OBSERVE_FEEDBACK
CURRENT_OBSERVED_PAIN = NONE_OPEN
NEXT_ENGINEERING_TASK = NONE_JUSTIFIED
DEPLOYMENT_SAFETY_BLOCKER_ISSUE_49 = CLEARED
AI_INQUIRY_SLICE_51 = ACCEPTED_AND_VERIFIED_PRODUCTION_LIVE
NO_TRANSFER_FIX_ISSUE_63 = PRODUCTION_VERIFIED
NEXT_PRODUCTION_CODE_DEPLOYMENT = NOT_BLOCKED
AI_BOUNDARY = INTERPRET_EXTRACT_SUGGEST_ONLY
OPERATIONAL_AUTHORITY = BOATOPS_PLUS_PRODUCTION_POSTGRESQL
CURRENT_SAFETY_EXCEPTION = NONE
DSH_MISSION_AUTHORITY = OWNING_GITHUB_ISSUE_LABELS
```

REAL-OPS-001 / Issue #41 is complete and accepted. Quick Paste is verified in production.

Issue #49 (non-root deployment privilege boundary + single-instance deployment mutex) is complete: merged via PR #58 and closed with `dsh:done`. It no longer blocks the next production code deployment. The deployment controls it introduced remain mandatory.

Issue #51 AI-INQUIRY-PARSE-001 is accepted and verified production live, per the AI-INQUIRY-PROD-SSOT-001 convergence recorded in `.project/CURRENT_STATE.yaml` (`owner_ai_inquiry_ssot`). The AI suggestion path remains interpret / extract / suggest only, human-confirmed, and never auto-submits. The no-transfer regression fix from #62 (PR #63) is production verified.

Issue #4 is complete: `main` protection is live (PR-before-merge, required checks `Quality and contracts` + `PostgreSQL concurrency`, force-push and deletion blocked).

No unresolved operational pain or deployment-safety blocker is currently open. The immediate boundary is genuine operation on the live production surface, with the smallest bounded change only when real use proves it necessary.

## Permanent question

Before adding anything:

> **如果不增加这个东西，下一个真实任务会完成不了吗？**

If no, do not build it now.

## Allowed now

- run the next genuine boat operation through the existing production Operator surface;
- capture concrete missing facts, friction, safety blockers, or observability gaps from real use;
- when real use proves a blocker, make the smallest bounded change through the existing production loop (local / automated validation, exact-SHA deployment, smoke check, observe);
- keep the AI path server-side and suggestion-only: interpret / extract fields already represented by the Inquiry flow, validate provider output against an explicit allowlist/schema, resolve entities deterministically against organization-scoped truth, and preserve human review plus manual-entry fallback;
- use an owning GitHub Issue with `dsh:ready / dsh:running / dsh:done / dsh:blocked` when DSH execution is required.

An open Issue without a DSH execution label is not automatically the current executable Mission.

## Not justified now

- speculative AI expansion (`speculative_ai_expansion = DO_NOT_BUILD_NOW`);
- direct AI database access or direct operational mutation;
- automatic Inquiry submission, Booking confirmation, Boat reservation, Trip-state transition, staffing, pricing, or accounting by AI;
- general Agent framework, AI Gateway platform, vector database, memory system, prompt-management platform, or autonomous tool-calling platform;
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

- exposing provider API keys, credentials, PII, or production backups in browser code, public Git, fixtures, screenshots, Issue text, or routine logs;
- sending unrelated historical customer data or broader database context to an external AI provider;
- treating model output as operational truth without BoatOps validation;
- allowing AI to reserve inventory, confirm a booking, mutate Trip status, or directly write production PostgreSQL;
- destructive synthetic testing against production data;
- unexplained irreversible production data mutation;
- bypassing organization isolation or transactional Boat occupancy checks;
- manual production source edits not represented in Git;
- deploying an unidentified or different Git SHA;
- weakening or bypassing the non-root execution boundary, single-instance deployment mutex, exact-SHA, backup acknowledgement, atomic switch, smoke, or rollback controls established by Issue #49;
- changing product intent, Acceptance Criteria, or Mission scope without Control Plane approval;
- claiming runtime success without evidence.

## Current next action

```text
NEXT = GENUINE OPERATION + FEEDBACK
-> run the next real boat operation on https://boatops.ayany.com/
-> observe real execution
-> record the next smallest proven gap
-> implement only when the next real task would fail without it
-> no speculative AI / product expansion

NO ACTIVE ENGINEERING MISSION
-> #51 is accepted and production live, not the next implementation task
-> #49 is cleared and does not block deployment
-> open a new owning GitHub Issue only for a proven operational blocker or gap
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
external AI output = untrusted suggestions only
boatops.ayany.com = real operator surface
Git history / PR / Issue / CI / deployment receipt = implementation and historical evidence
DSH labels = handoff interface only
```
