# BoatOps Current Guardrail

Updated: 2026-08-27 Asia/Bangkok

This file records only the immediate boundary for the next real task. It is not a phase engine or second task system.

## Current decision

```text
PRIMARY_GOAL = REAL_OPERATOR_USE
PRODUCTION_SURFACE = https://boatops.ayany.com/
PRODUCTION_SHA = 4bdd541cb739b257153dc9fb45a7eb7ba97bd40e
PRODUCTION_DEPLOYMENT = VERIFIED_LIVE
NEXT_OPERATION = WAIT_FOR_NEXT_GENUINE_OPERATION
CURRENT_OBSERVED_PAIN = ISSUE_51_AI_INQUIRY_PARSE_001
NEXT_ENGINEERING_TASK = ISSUE_51_AI_INQUIRY_PARSE_001
AI_BOUNDARY = INTERPRET_EXTRACT_SUGGEST_ONLY
OPERATIONAL_AUTHORITY = BOATOPS_PLUS_PRODUCTION_POSTGRESQL
CURRENT_SAFETY_EXCEPTION = ISSUE_49_DEPLOYMENT_PRIVILEGE_BOUNDARY
NEXT_PRODUCTION_CODE_DEPLOYMENT = BLOCKED_BY_ISSUE_49
DSH_MISSION_AUTHORITY = OWNING_GITHUB_ISSUE_LABELS
```

REAL-OPS-001 / Issue #41 is complete and accepted. Quick Paste is verified in production, but real operator input has now exposed a concrete parsing gap: deterministic parsing can misread bilingual order semantics such as transfer intent and can fail to resolve a named Boat reliably. The durable, PII-free task contract is Issue #51.

Issue #4 is complete: `main` protection is live (PR-before-merge, required checks `Quality and contracts` + `PostgreSQL concurrency`, force-push and deletion blocked).

Issue #49 remains the current deployment-safety gate. It blocks the NEXT production code deployment. It does not block real operations on the already-live production surface, and it does not block bounded local/branch/PR implementation and validation of Issue #51.

## Permanent question

Before adding anything:

> **如果不增加这个东西，下一个真实任务会完成不了吗？**

If no, do not build it now.

## Allowed now

- run the next genuine boat operation through the existing production Operator surface; AI is not required for operations to continue;
- implement and validate the bounded Issue #51 AI-assisted Inquiry parser on a branch / PR;
- keep the AI call server-side with provider credentials outside the browser and repository;
- use AI only to interpret / extract / suggest fields already represented by the Inquiry flow;
- validate provider output against an explicit allowlist/schema;
- resolve Boat and other entity names deterministically against the current organization before suggesting IDs;
- preserve human review and the existing manual Create Inquiry action;
- preserve manual entry as the fallback for provider failure, timeout, 429, malformed output, or disabled AI;
- capture further concrete missing facts, friction, safety blockers, or observability gaps from real use;
- use an owning GitHub Issue with `dsh:ready / dsh:running / dsh:done / dsh:blocked` when DSH execution is required.

An open Issue without a DSH execution label is not automatically the current executable Mission.

## Not justified now

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
- requesting the next production code deployment while Issue #49 remains incomplete;
- changing product intent, Acceptance Criteria, or Mission scope without Control Plane approval;
- claiming runtime success without evidence.

## Current next action

```text
ISSUE #51 AI-INQUIRY-PARSE-001
-> architecture / contract in GitHub
-> bounded implementation on branch / PR
-> tests + strict output validation
-> no customer PII in public Git
-> NO production deployment while Issue #49 is open

IN PARALLEL:
NEXT GENUINE OPERATION
-> existing manual Operator workflow remains available
-> observe real execution
-> record the next smallest proven gap
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
