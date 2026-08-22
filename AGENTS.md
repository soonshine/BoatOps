# AGENTS.md — BoatOps 项目 Agent 指引

本文件是 BoatOps 仓库的 Agent / Fresh AI 入口。

## Bootstrap 顺序

1. `.project/PROJECT_CHARTER.md` — 项目使命、边界、永久原则。
2. `.project/CURRENT_STATE.yaml` — 当前运行/部署事实。
3. `.project/CURRENT_GATE.md` — 当前允许事项、下一步与立即边界。
4. `AI_COLLABORATION.md` — AI 协同与 GitHub/DSH 交接映射。
5. `ENVIRONMENT.md`、`scripts/check.sh` — 环境与项目校验路径。
6. 若被分配执行任务，再读取该任务的 owning GitHub Issue / PR。

## 权威来源（路由）

- **项目事实/决策/状态**：以 `.project/**` 的对应权威文件为准；不要从聊天历史恢复项目事实。
- **当前可执行 DSH Mission**：以 owning GitHub Issue 为准。只有带 `dsh:ready` 或 `dsh:running` 的 Issue 才是当前 DSH 执行交接；普通开放 Issue 不自动等于当前任务。
- **AI 协同协议**：approved AI-collaboration ref = `250a18f0542f5d6f87f29083965d9489da943472`（soonshine/ai-collaboration）。
- **环境与校验**：见 `AI_COLLABORATION.md`、`ENVIRONMENT.md`、`scripts/check.sh`。

## 对 Agent 的要求

1. 修改任何产品事实前，先读对应 BoatOps authority。
2. 遵循 approved AI 协同协议；Worker Routing 与 Control Plane 规则以 `AI_COLLABORATION.md` 指向的 approved protocol 为准。
3. DSH / Courier 不得把 runtime/session 状态写成 BoatOps 项目事实；结果回写到 owning Issue，并以 commit、PR、tests、deployment evidence 等作为事实证据。
4. 改动后运行 `scripts/check.sh`；涉及数据库、部署或生产时，再执行任务明确要求的额外验证。
