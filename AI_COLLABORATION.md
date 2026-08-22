# BoatOps AI Collaboration Reference

Repository: [`soonshine/ai-collaboration`](https://github.com/soonshine/ai-collaboration)

Approved ref: 3dd570cb6b5fab05f47d1c511b6b11489d04bd96

This immutable commit is the approved cross-project Worker / Task / Handoff / execution-process contract for BoatOps. A moving branch, later commit, copied chat, or Worker memory is not a substitute.

## Authority boundary

```text
BoatOps main + .project
= BoatOps business / inventory / safety / operations / deployment / Gate Truth

ai-collaboration approved ref
= cross-project Worker / Task / Handoff / execution-process contract
```

BoatOps project-local authority wins on BoatOps domain, safety, operations, schema, data, deployment, and authorization. Stop and report ambiguity rather than inventing an override or expanding scope. The shared protocol grants no Merge, Deployment, TEST/Production access, real-data, Cutover, Tag, or Release authority.

## Runtime / Adapter terminology

For BoatOps handoff discussions, use these terms strictly:

```text
Harness
= the live local DeepSeek Harness execution runtime

DSH
= `soonshine/dsh`, the GitHub ↔ Harness adapter project
= watcher / Mission claim / routing gate / Courier dispatch / durable GitHub return
```

DSH is not a synonym for Harness. Live Harness version/profile/provider/model/adapter/session facts are runtime evidence and must not be inferred from the DSH repository or old conversations.

## Project Control Plane / Dynamic Worker Routing

BoatOps 的日常用户入口是 BoatOps 项目 ChatGPT 会话；该会话承担当前任务的 Control Plane 角色。

```text
User
→ BoatOps ChatGPT Session / Control Plane
→ Project GitHub Issue / bounded Mission
→ Authorized Courier / Execution Adapter
→ Harness / authorized Execution Runtime
→ Selected Worker
→ BoatOps Repository
→ Result + Evidence written back to the owning Issue
→ BoatOps ChatGPT Session / Control Plane
```

BoatOps 不定义项目级默认 Worker，也不固定绑定 Hermes、DeepSeek、Codex、Antigravity 或其他 Worker。

Control Plane 按 approved `ai-collaboration` Task Contract，根据任务类型、复杂度、成本、可靠性和所需能力定义 Worker 要求与约束；Courier / Execution Runtime 按 Task Contract 执行路由、连续性协调和结果运输。

具体 Worker 能力、recommended worker、fallback worker、official Codex required 等路由规则，以 approved `ai-collaboration` ref 为准。

用户正常情况下不需要在 Courier / DSH / Worker 会话重复下令；若当前工具集成无法直接完成 Project Session → Courier 调用，手工跨会话运输仅作为临时 fallback。

## BoatOps GitHub / DSH handoff adapter

This section only maps the shared execution contract onto BoatOps GitHub. It does not implement Harness runtime behavior and does not create another project state system. DSH is the external adapter implementation, not the runtime itself.

### Owning task record

The **owning GitHub Issue** is the durable Task Packet / bounded Mission envelope for DSH handoff.

A Mission prepared for automated execution must make these four items explicit in the Issue body:

- `GOAL`
- `ACCEPTANCE`
- `ALLOWED`
- `STOP / ESCALATE`

`READ FIRST`, task-specific verification commands, required inputs, and evidence expectations may be added when needed. Do not create a second Mission document or database merely to duplicate the Issue.

### Handoff labels

BoatOps reserves these GitHub labels as the DSH execution handoff interface:

```text
dsh:ready
dsh:running
dsh:done
dsh:blocked
```

They are **not** BoatOps business lifecycle states and are not a second SSOT.

- no `dsh:*` label: not armed for DSH execution;
- `dsh:ready`: Control Plane has authorized the bounded Mission for pickup;
- `dsh:running`: the DSH/Harness execution path has claimed it and is executing;
- `dsh:done`: execution has written completion evidence;
- `dsh:blocked`: execution needs Control Plane judgment before continuing.

For a live DSH handoff, keep one current `dsh:*` status label on the owning Issue.

### Durable writeback

The DSH-managed execution path writes its result back to the **same owning Issue**. The result comment should contain only the evidence needed for Control Plane judgment:

```text
STATUS
SUMMARY
VERIFICATION
EVIDENCE: commit / PR / tests / deployment receipt as applicable
```

For `dsh:blocked`, also include:

```text
BLOCKER
REQUIRED DECISION
EVIDENCE
```

Issue text/comments are the durable handoff index. Repository code, commits, PRs, tests, CI, deployment receipts, and production PostgreSQL remain the underlying facts according to BoatOps authority.

### Project-side non-goals

BoatOps does not add its own watcher, scheduler, Mission DB, runtime registry, workflow engine, or second execution state system for this integration. DSH adapter implementation and Harness runtime state belong outside this repository.

## Approved read set

Read these files from the approved ref, in addition to that repository's `AGENTS.md` and `README.md`:

1. [`EXECUTION_DOCTRINE.md`](https://github.com/soonshine/ai-collaboration/blob/3dd570cb6b5fab05f47d1c511b6b11489d04bd96/EXECUTION_DOCTRINE.md)
2. [`ENVIRONMENT_CONTRACT.md`](https://github.com/soonshine/ai-collaboration/blob/3dd570cb6b5fab05f47d1c511b6b11489d04bd96/ENVIRONMENT_CONTRACT.md)
3. [`RUNTIME_PROFILES.md`](https://github.com/soonshine/ai-collaboration/blob/3dd570cb6b5fab05f47d1c511b6b11489d04bd96/RUNTIME_PROFILES.md)
4. [`WORKER_PROTOCOL.md`](https://github.com/soonshine/ai-collaboration/blob/3dd570cb6b5fab05f47d1c511b6b11489d04bd96/WORKER_PROTOCOL.md)
5. [`TASK_CONTRACT.md`](https://github.com/soonshine/ai-collaboration/blob/3dd570cb6b5fab05f47d1c511b6b11489d04bd96/TASK_CONTRACT.md)
6. [`HANDOFF_PROTOCOL.md`](https://github.com/soonshine/ai-collaboration/blob/3dd570cb6b5fab05f47d1c511b6b11489d04bd96/HANDOFF_PROTOCOL.md)
7. [`COURIER_PROTOCOL.md`](https://github.com/soonshine/ai-collaboration/blob/3dd570cb6b5fab05f47d1c511b6b11489d04bd96/COURIER_PROTOCOL.md) when acting as Courier
8. [`PROJECT_ONBOARDING.md`](https://github.com/soonshine/ai-collaboration/blob/3dd570cb6b5fab05f47d1c511b6b11489d04bd96/PROJECT_ONBOARDING.md) when onboarding or maintaining protocol integration

Verify the checkout before relying on it:

```bash
git -C <ai-collaboration-workspace> rev-parse HEAD
```

Expected result:

```text
3dd570cb6b5fab05f47d1c511b6b11489d04bd96
```

Keep the protocol checkout outside BoatOps. Do not vendor, fork, or duplicate the shared protocol into this repository.
