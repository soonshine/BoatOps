# BoatOps Community

BoatOps Community 是一个可自托管的船务运营系统项目，面向中小型包船、游艇、快艇和当地活动运营商。

> **当前状态：** `ALPHA_CANDIDATE / CANDIDATE_DEPLOYED / NOT_RELEASED`
> 首个候选实例已部署到 `https://boatops.ayany.com`，但仍未完成全部验收门禁，也没有正式版本、Tag 或公开发行许可证。

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
- 独立的内部 Operations API 契约。

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

仍未闭环：

- 已部署候选通过健康检查、回滚和再前滚；后续加固归档仍在远端预检阶段，当前不得宣称形成正式不可变 release；
- 本次 Git 文档和空白规范化发生在归档生成之后，归档的 source-tree hash 不等于本次 Git 提交；
- 燃油、仓库、采购、收付款、现金日结和完整利润模块；
- CSV/XLSX 导入导出及正式迁移流程；
- 正式许可证、独立安全审查和第二运营商安装验证。

详细候选记录见 `docs/releases/0.0.1-deployed-alpha-candidate.md`。

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

1. 补齐并执行剩余真实竞态测试；
2. 完成公网 UI/API QA；
3. 生成不可变发布归档、manifest、SHA-256 和回滚记录；
4. 冻结许可证并加入正式 LICENSE/SPDX 边界；
5. 通过验收后再创建 Tag 或 GitHub Release；
6. ChannelHub 只消费已形成可引用版本的契约，不引用 BoatOps 内部实现。

## 安全与数据

禁止提交 API Key、Token、密码、Cookie、Webhook Secret、数据库连接串、服务器登录信息、真实客户资料、合同、报价、财务流水和生产备份。公开示例必须使用人工虚构数据，并通过 secret 与 PII 扫描。
