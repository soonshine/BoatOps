# V3 架构衔接与开发增量计划

日期：2026-10-29。架构基线：[GitHub Control 已合并 V3](https://github.com/soonshine/github-control/blob/f5f8c1ce83b27b7d077ff87cd4bd57158b112e56/docs/system-architecture/soonshine-ecosystem-architecture-v3.md)。
本文件只记录本项目的 V3 增量与依赖；原业务合同、现行计划和 approved AI 协作 ref 继续有效。此 PR 合并前是候选；任务状态和结果以 owning Issue/PR 为准。

## 本项目职责

组织隔离的船务履约与整船时间区间库存；命令复用 Application Actions/PostgreSQL 事务。AI 只解释、提取、建议，由操作人员确认。

## 当前事实与目标的区别

CURRENT_GATE 明确 NEXT_ENGINEERING_TASK=NONE_JUSTIFIED；等待下一笔真正操作的反馈。V3 不推翻这个门禁，不创建为架构完整性而做的功能。

## 开发顺序

维持 genuine operation → observed pain → 最小 Issue/PR。未来 Reyky/Mukuy 商业交接应携带固定 revision/商业快照，BoatOps 重新裁定资源与履约，不接受外部“已确认”覆盖库存事务。本轮不新发 BoatOps 开发任务。

没有需要为 V3 重复建立的既有开发任务。

本轮没有仅为架构一致性而新增的开发功能。下一步只从既有真实任务或明确反馈立项。

## 派单、回写与独立审核

Owner 后续安排 DSH 认领，本轮不执行本计划、不加 dsh:ready。派单前重新核对最新 main SHA、文档/实现依赖、唯一认领、所需工具/数据/目标主机；watch set、CI admission 与主机能力分别核实，缺输入 STOP_AND_REPORT。既有任务的原批准/认领继续有效，不被本增量计划自动重开或改派。

本轮新增 V3 任务只走任务分支 + Draft PR；DSH 不自合并、不自验收。完成后在 owning Issue 和 PR 写 base/head SHA、改动、命令/exit code/平台/版本/原始脱敏输出、数据/产物哈希、BLOCKERS 与 NEXT。没有执行的项目写 NOT_RUN，不把 dsh:done、PR、合并、部署或“已安装”当作最终业务成功。

独立 Codex 会话审核同一 exact PR HEAD，必要时在干净隔离目录离线复算，向 owning Issue/PR 返回 PASS / REWORK / BLOCKED；发现问题交还原任务/分支。Owner 决定合并与必要的环境/业务验收，审核会话不代做开发、部署或交易。

## 验证与持续边界

后续真正实现按 scripts/check.sh 及 PostgreSQL 并发门禁；本轮无运行/部署/数据库/AI写入变更。

本次更新仅含文档与导航，不执行后续开发或现场操作。完整源码检查若未运行，应在 PR 明记 NOT_RUN；文档链接/差异检查不替代源码、运行和业务验收。保留凭据、个人信息、数据库、运行账本与生产日志的既有私有边界。

只在现有消费者需要时增加业务接口或结果展示。各业务可保留适合的调度器；调度归属记录主机、任务标识、触发/权限/重试和结果证据，展示区分触发、投递、进程结束、业务成功并标时间与来源。

## 原有权威入口

- [.project/PROJECT_CHARTER.md](.project/PROJECT_CHARTER.md)
- [.project/CURRENT_STATE.yaml](.project/CURRENT_STATE.yaml)
- [.project/CURRENT_GATE.md](.project/CURRENT_GATE.md)
- [ENVIRONMENT.md](ENVIRONMENT.md)
- [scripts/check.sh](scripts/check.sh)
