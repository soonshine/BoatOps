# AGENTS.md — BoatOps 项目 Agent 指引

本文件是 BoatOps 仓库的 agent 指令入口（2026-08-18, BOATOPS-AI-COLLAB-002）。

## 权威来源（路由）

- **项目事实/决策/状态**：一律以 `.project/**` 下的权威文件为准；本文件不复制项目事实，只做路由。
- **AI 协同协议**：approved AI-collaboration ref = `3dd570cb6b5fab05f47d1c511b6b11489d04bd96`（soonshine/ai-collaboration）。
- **环境与校验**：见 `AI_COLLABORATION.md`、`ENVIRONMENT.md`、`scripts/check.sh`。

## 对 Agent 的要求

1. 修改任何产品事实前，先读 `.project/**` 对应权威文件。
2. 遵循 approved AI 协同协议（ref `3dd570cb6b5fab05f47d1c511b6b11489d04bd96`）的约定与红线；Worker Routing 与 Control Plane 规则以 `AI_COLLABORATION.md` 指向的 approved protocol 为准。
3. 改动后运行 `scripts/check.sh` 校验，确保无漂移。
