# BoatOps AI Collaboration Reference

Repository: [`soonshine/ai-collaboration`](https://github.com/soonshine/ai-collaboration)

Approved ref: `dc4e4c25a6059ebe1351e9da00f5867b02ffea23`

This immutable commit is the approved cross-project AI role / Task / Handoff / Execution Tool selection contract for BoatOps. A moving branch, later commit, copied chat, or Worker memory is not a substitute.

## Authority boundary

```text
BoatOps main + .project
= BoatOps business / inventory / safety / operations / deployment / Gate Truth

ai-collaboration approved ref
= cross-project AI role / Task / Handoff / Execution Tool selection contract

BoatOps ChatGPT Session
= Control Plane / Mission definition / priority / final judgment
```

BoatOps project-local authority wins on BoatOps domain, safety, operations, schema, data, deployment, and authorization. Stop and report ambiguity rather than inventing an override or expanding scope. The shared protocol grants no Merge, Deployment, TEST/Production access, real-data, Cutover, Tag, Release, repository-administration, or secret/permission authority unless the bounded Mission explicitly grants the required action.

Because BoatOps is public, never write real customer/order data, private supplier terms, credentials/tokens, production secrets, or sensitive runtime evidence into the repository or public Issue/PR surface.

## Runtime / Adapter terminology

For BoatOps handoff discussions, use these terms strictly:

```text
Execution Tool
= replaceable live execution capability, for example currently available DeepSeek Harness, Codex CLI, or another authorized Tool

DSH
= `soonshine/dsh`, the Execution Adapter / Tool Dispatcher implementation
= Mission discovery / claim / Courier dispatch / Tool dispatch seam / durable GitHub return
```

DSH is not a synonym for an Execution Tool. Live Tool version/profile/provider/model/session/process/readiness facts are runtime evidence and must not be inferred from the DSH repository or old conversations. Provider / Model / Account / API / quota / model fallback remain Tool-native configuration rather than BoatOps or DSH governance.

## Project Control Plane / Execution Boundary

BoatOps 的日常用户入口是 BoatOps 项目 ChatGPT 会话；该会话承担当前任务的 Control Plane 角色。

```text
User
→ BoatOps ChatGPT Session / Control Plane
→ Project GitHub Issue / bounded Mission
→ Authorized Courier / DSH adapter
→ selected Execution Tool
→ Worker
→ BoatOps Repository
→ Result + Evidence written back to the owning Issue / PR
→ BoatOps ChatGPT Session / Control Plane
```

职责保持：

```text
ChatGPT / Control Plane
= 为什么做 / 做什么 / Mission 边界 / 优先级 / Acceptance / 最终判断

Execution Tool
= 在已授权 Mission 内具体完成执行，包括其具备的原生 Git/GitHub coding 能力

BoatOps GitHub
= durable project / task / result Truth
```

若所选 Execution Tool 具备原生 Git/GitHub 能力，它可以在 `ALLOWED` 范围内直接完成正常 coding loop，例如：

- 读取 repository / Issue / PR / CI；
- 修改代码并执行 tests / validation；
- commit；
- push 已授权的 task branch；
- 创建或更新该任务 PR；
- 读取 CI / review feedback 并做普通 in-scope 修复；
- 把 Result + Evidence 写回 owning Issue / PR。

这不等于把 BoatOps 项目管理权交给 Execution Tool。除非 Mission 另有明确授权，否则 Tool 不得自行改变项目优先级、扩大 scope、改变 Acceptance、修改 BoatOps authority、改变 repository governance / branch protection / settings / permissions / secrets、执行 destructive repository administration、直接写 main、merge、cutover/deploy，或最终验收自己的工作。

BoatOps 不定义项目级默认 Tool，也不固定绑定 DeepSeek Harness、Codex CLI、Hermes、Antigravity 或其他执行工具。新 Mission 的 routing 使用 approved `TASK_CONTRACT.md`：

```text
EXECUTION_CLASS: ROUTINE | STANDARD | CRITICAL
TOOL_POLICY: AUTO | PINNED
REQUIRED_CAPABILITIES:
PINNED_TOOL:
```

Tool availability 必须来自当前 live execution environment。用户正常情况下不需要在 Courier / DSH / Worker 会话重复下令；手工跨会话运输仅作为集成缺失时的 fallback。

## BoatOps GitHub / DSH handoff adapter

This section maps the shared execution contract onto BoatOps GitHub. It does not implement Tool runtime behavior and does not create another project state system.

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

They are **not** BoatOps business lifecycle states, are not live Execution Tool process truth, and are not a second SSOT.

- no `dsh:*` label: not armed for DSH execution;
- `dsh:ready`: Control Plane has authorized the bounded Mission for pickup;
- `dsh:running`: the DSH execution path has claimed it;
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

BoatOps does not add its own watcher, scheduler, Mission DB, runtime registry, workflow engine, Tool Registry, or second execution state system for this integration. DSH adapter implementation and Execution Tool runtime state belong outside this repository.

## Approved read set

For normal bounded Missions, read only what the task needs from the approved ref, normally:

1. `AGENTS.md`
2. `EXECUTION_DOCTRINE.md`
3. `TASK_CONTRACT.md`
4. `HANDOFF_PROTOCOL.md`
5. `COURIER_PROTOCOL.md` when acting as Courier
6. `COURIER_CORE_SKILL.md` when acting as Courier

Keep the protocol checkout outside BoatOps. Do not vendor, fork, or duplicate the shared protocol into this repository.
