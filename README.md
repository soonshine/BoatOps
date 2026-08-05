# BoatOps Community

BoatOps Community 是一个可自托管的船务运营系统项目，面向中小型包船、游艇、快艇和当地活动运营商。

> **当前状态：** `ALPHA_CANDIDATE / CANDIDATE_DEPLOYED / NOT_RELEASED`
> 首个候选实例已部署到 `https://boatops.ayany.com`，但仍未完成全部验收门禁，也没有正式版本、Tag 或公开发行许可证。
> `2026-08-04` 本地 `0.0.2 operations-finance` 是运营财务基础；当前工作树上的 `0.0.3 finance-reversals` 与 `0.0.4 cash-activity-local` 是本地候选。它们均为 `LOCAL_WORKTREE / NOT_DEPLOYED / NOT_RELEASED`，不能据此更新公网 `0.0.1 deployed alpha candidate` 状态。

## 产品职责

BoatOps 是库存与运营事实的唯一真实来源。长期职责包括：

- 船只、资源、营业日历、可售窗口和周转缓冲；
- `INQUIRY / HOLD / CONFIRMED / BLOCKED / CANCELLED / EXPIRED / COMPLETED` 状态；
- HOLD、确认、改期、取消、封船、冲突裁决和幂等处理；
- 出航任务、船员、检查表、异常和附件；
- 燃油、发动机小时、物资、采购、消耗、损耗和盘点；
- 费用、收付款、退款、现金日结和经营利润；
- 权限、审计、导入、对账、备份和恢复。

当前 Alpha 候选只实现了其中一部分，不能据此宣称完整 V0.1 已完成。

## Inventory Provider API

BoatOps 保存 **Inventory Provider API** 的权威、版本化契约，当前候选包含：

- 可售查询；
- 创建与释放 HOLD；
- 确认、改期和取消 Booking；
- 库存 revision；
- 幂等规则、稳定错误码和公开事件 Schema；
- 独立的内部 Operations API 契约，包括 BLOCK、Trip、付款账户、燃油、费用、物资流水和成本查询。

外部系统只能通过正式契约申请库存变更。数据库约束和 BoatOps 业务事务拥有最终裁决权。

## 与 ChannelHub 的边界

[ChannelHub Community](https://github.com/soonshine/ChannelHub) 是独立的渠道管理系统。

- ChannelHub 通过已发布的 Inventory Provider API 契约调用 BoatOps；
- ChannelHub 不得直接读写 BoatOps 业务数据库；
- ChannelHub 不得引用 BoatOps ORM、migration 或内部业务模型；
- 渠道预计结算属于 ChannelHub，实际到账、退款和利润属于 BoatOps；
- BoatOps 不可用时，外部系统不得凭缓存擅自确认库存。

## Ayany 部署边界

`boatops.ayany.com` 是首个候选部署。真实船只、客户、订单、价格、合同、Google Sheet 迁移数据、财务、服务器配置和凭据不得进入本公开仓库或公开演示数据。

公开仓库仅允许人工虚构示例；部署存在不代表 Ayany 私有数据或部署配置已经开源。

## 当前 Alpha 候选范围

当前候选已经实现并验证：

1. 船期可售查询、HOLD、确认、改期、取消和 BLOCK；
2. Bearer Token 哈希认证、可信组织上下文和幂等处理；
3. 船员、检查表及 Trip 准备、出发、返航、完成状态机；
4. Booking 私有价格快照与公开 API / Outbox 金额隐私边界；
5. Inventory Provider 与 Operations OpenAPI 契约及事件 Schema；
6. PostgreSQL 16 排斥约束、100 并发 HOLD 门禁、备份和隔离恢复验证；
7. HOLD 后台自动过期、队列、调度、HTTPS 和证书续期。
8. 本地 `0.0.2` 已加入付款账户、费用分类、燃油日志、费用明细、饮料/消耗品移动平均库存流水、出航成本和单船日成本查询；尚未部署。
9. 本地 `0.0.3` 冲销候选已加入燃油、费用、库存冲销，`finance_reversals`、库存补偿、幂等/审计/组织隔离及 `/demo` 近期流水操作台；尚未部署或发布。
10. 本地 `0.0.4` Gate B 在 `/demo` 为每个启用的虚构现金账户显示组织时区今日摘要和最近 7 个本地营业日（最多 200 条）的只读派生现金流水；页面通过既有 Operations controller 正式读方法读取，不提供手工现金记账或编辑。

仍未闭环：

- 已部署候选通过健康检查、回滚和再前滚；后续加固归档仍在远端预检阶段，当前不得宣称形成正式不可变 release；
- 本次 Git 文档和空白规范化发生在归档生成之后，归档的 source-tree hash 不等于本次 Git 提交；
- 当前运营财务切片尚缺凭证文件上传、真实收付款、退款、现金日结、完整利润和审批后台；
- 运营财务切片尚未执行 PostgreSQL 并发、备份恢复、公网部署或真实业务样本验收；
- CSV/XLSX 导入导出及正式迁移流程；
- 正式许可证、独立安全审查和第二运营商安装验证。

已部署候选记录见 `docs/releases/0.0.1-deployed-alpha-candidate.md`；本地运营财务基础见 `docs/releases/0.0.2-operations-finance-local-candidate.md`；本地冲销候选见 `docs/releases/0.0.3-finance-reversals-local-candidate.md`；本地现金活动候选见 `docs/releases/0.0.4-cash-activity-local-candidate.md`。

## 非目标

- 不在 V0.1 替代泰国法定会计和税务申报系统；
- 不把 WordPress、Google Sheet、OTA 或渠道后台变成库存主库；
- 不在第一版提供共享数据库的公有多租户 SaaS；
- 不承诺安装后自动接通所有 OTA；
- 不在没有来源和审批规则时自动定价或自动批准财务记录。

## 许可证状态

许可证组合目前为 `PROPOSED_NOT_FROZEN`：

- 服务端核心候选：`AGPL-3.0-only`；
- OpenAPI、JSON Schema、SDK、CLI 和示例客户端候选：`Apache-2.0`；
- 正式采用前仍需完成兼容性和第三方条款审阅。

本仓库目前没有正式 `LICENSE`；`composer.json` 暂时保持 `proprietary`，不能把公开可见代码视为已经按上述候选许可证发行。

## 下一步门禁

1. 完成凭证文件、真实收付款/退款、现金日结、利润和审批后台；
2. 使用脱敏真实样本冻结字段，执行 PostgreSQL 并发、备份恢复和金额/库存复算；
3. 完成 Google Sheet dry-run、冲突清单和逐单对账；
4. 再完成公网 UI/API QA、不可变归档、manifest、SHA-256 和回滚记录；
5. 冻结许可证并加入正式 LICENSE/SPDX 边界，通过验收后再创建 Tag 或 GitHub Release；
6. ChannelHub 暂停开发，等 BoatOps 闭环和 Inventory Provider 契约形成可引用版本后再启动。

## 安全与数据

禁止提交 API Key、Token、密码、Cookie、Webhook Secret、数据库连接串、服务器登录信息、真实客户资料、合同、报价、财务流水和生产备份。公开示例必须使用人工虚构数据，并通过 secret 与 PII 扫描。


## 本地虚构演示站

> **LOCAL_WORKTREE / NOT_DEPLOYED / NOT_RELEASED**

本工作树提供基于 Laravel Blade 的 `/demo` 本地测试站，展示同一虚构组织下的 Plan A（虚构演示船）与 Plan B（虚构演示船）两艘整船资源、未来 7 天 `allocations` / `trips` 排期、运营费用、移动平均库存流水、近期燃油/费用/库存流水与行内冲销操作，以及按启用现金账户分组的今日现金摘要和最近 7 个本地营业日派生流水。现金区只读，不能手工记账或编辑。所有内容均为人工虚构演示，不连接 Google Sheet、生产库、真实客户或真实财务，也不对接 ChannelHub、OTA 或 WordPress。

入口默认 fail closed；仅在 `BOATOPS_DEMO_SITE_ENABLED=true`、环境为 `local/testing` 且精确虚构组织和最小权限 actor 均存在时开放。浏览器不接收 Bearer Token 或 `BOATOPS_DEMO_TOKEN`。迁移、幂等 seed、启动和验证步骤见 `docs/demo-site-local.md`。
