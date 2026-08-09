# BoatOps Community

BoatOps Community 是一个可自托管的船务库存与运营管理系统，面向包船、游艇、快艇、当地活动运营商及其他需要管理整船库存、订单与出航执行的组织。

> **当前状态：** `ALPHA / D1_FICTIONAL_DEMO_COMPLETE / NOT_PRODUCTION / NOT_RELEASED`
>
> 当前 reviewed product source 为 `f9503b598b174b7a6891fcde0d984514a3cd0fcd`。该 source 已通过 GitHub CI 的 Quality/contracts 与 PostgreSQL concurrency jobs，并以**纯虚构、隔离 SQLite**完成 D1 Demo 验收。没有 Tag、GitHub Release、真实数据迁移或生产库存启用。

## 产品定位

BoatOps 是**通用、organization-scoped 的船务运营产品**，不是 Ayany 专用系统。

- Ayany 不应被硬编码为 tenant、船东、运营主体或必需集成方；
- `boatops.ayany.com` 是当前候选部署域名，不代表该部署中的船只属于 Ayany；
- 当前 Plan A / Plan B 两艘船场景是建立和验证系统的参考运营场景；
- 船只所有权、运营权、营业时间、buffer、HOLD 规则、价格、佣金、天气规则和人员权限属于具体 deployment / organization 配置；
- 未来其他公司应能独立部署 BoatOps，而不依赖 Ayany、WordPress、Google Sheet 或 ChannelHub。

## BoatOps 的职责

BoatOps 负责保存运营与库存事实，包括：

- 船只/资源、营业窗口和占用区间；
- `INQUIRY / HOLD / CONFIRMED / BLOCKED / CANCELLED / EXPIRED / COMPLETED` 等业务状态；
- HOLD、确认、改期、取消、封船/解封和库存 revision；
- 出航 Trip、船员、检查表和执行状态；
- slot catalog、兼容规则、自定义/指定日期档期；
- 运营费用、燃油、库存、现金流水及相关审计基础；
- Operator 权限、审计、导入、对账、备份和恢复。

一个能力存在于 source 中，不等于它已经被生产启用或通过真实业务验收。

## 核心库存原则

BoatOps 的库存模型是：

**整船资源 + occupied interval**。

- Service time、buffer-before、buffer-after 是不同事实；
- HOLD、CONFIRMED booking 和 BLOCK 都能形成权威占用；
- Calendar/availability 页面只是 projection；
- 最终 HOLD / Confirm 必须重新由数据库事务裁决；
- 生产冲突最终由 PostgreSQL 事务与约束判定，UI、缓存、表格或外部渠道不能覆盖数据库结果。

## 当前已存在的主要能力

当前 `main` 已包含：

1. Availability、HOLD、Booking Confirm/Amend/Cancel、BLOCK/Release；
2. Inventory Provider API、幂等规则、稳定错误码和事件 Schema；
3. Trip 的 crew/checklist 与 prepare/depart/return/complete 基础状态机；
4. Slot catalog、compatibility、Schedule API 和库存日历投影；
5. G1 Operator MVP：登录、7/30 天日历、Inquiry/HOLD、Confirm、Amend、Cancel、BLOCK、Audit；
6. operations-finance、fuel、expense、stock、cash posting、reversal 等候选基础；
7. PostgreSQL concurrency CI、SQLite migration round-trip、contract validation 和 dependency audits。

其中 G1 的库存 mutation 已复用 shared Application/domain actions，Operator UI 不应再建立平行业务规则路径。

## D1 虚构 Demo

D1 release：

`D1_G1_20260809T045741Z`

D1 使用 exact source：

`f9503b598b174b7a6891fcde0d984514a3cd0fcd`

并且：

- **没有 D1 source change**；
- Public runtime 使用 `public_read_only`；
- Private Operator 仅通过 SSH tunnel 到 `127.0.0.1:18082` 访问；
- 两个 runtime 使用同一份隔离虚构 D1 SQLite；
- D1 SQLite SHA256 为 `62299484458b8e6f63ca7f457ae713d6d3812e31b654ada8847a6d302596b08f`；
- `integrity_check=ok`，FK violations=`0`；
- actual D1 -> D0 rollback 已通过；
- actual D0 -> D1 restore 已通过；
- rollback script authoritative SHA256 为 `0f785385bd57c8165470f436e71009a11e4971b2687a48d1da36e5e2bacad11a`；
- final evidence checksum verification 为 `25/25 PASS`；
- 全部数据为 synthetic/fictional；
- 没有 production PostgreSQL、真实客户、真实订单、真实财务或真实船务数据。

详细治理记录见 `.project/D1_GOVERNANCE_CLOSURE.md`。

## 与 ChannelHub 的边界

[ChannelHub Community](https://github.com/soonshine/ChannelHub) 是独立系统。

- ChannelHub 通过正式、版本化 API 调用 BoatOps；
- ChannelHub 不得直接读写 BoatOps DB；
- ChannelHub 不得共享 BoatOps ORM、migration 或内部业务模型；
- BoatOps 不可用时，外部系统不得凭缓存擅自确认库存。

ChannelHub 当前保持暂停，除非未来产品 Gate 明确授权。

## Ayany 部署边界

`boatops.ayany.com` 是目前的候选 Demo 部署域名之一。

这仅说明部署位置，不说明：

- 当前船只由 Ayany 所有；
- BoatOps 是 Ayany 专用产品；
- Ayany 的真实客户/订单/价格/财务已经进入系统；
- Ayany 的真实业务规则已经冻结；
- 当前 Demo 可以被视为 production。

公开仓库和公开 Demo 只能包含人工虚构数据。

## 当前仍未闭环

- 下一 Product Gate 尚未定义；
- 实际运营 organization / tenant 尚未作为 production deployment 冻结；
- 实际船只所有权/运营权关系尚未作为产品事实冻结；
- 实际营业时间、buffer、HOLD TTL、weather、slot compatibility 等规则尚未冻结；
- Production PostgreSQL 尚未启用；
- 真实 Operator 身份与权限尚未启用；
- Google Sheet migration/reconciliation/cutover 尚未授权；
- finance/stock 候选能力尚未完成真实业务闭环验收；
- 正式 LICENSE、Tag、GitHub Release、第二运营商安装验证尚未完成。

## 下一步门禁

当前不预设 `G2A` 或 `G2B`。

下一步必须先执行 `DEFINE_NEXT_PRODUCT_GATE`：

1. 明确下一个要解决的真实业务结果；
2. 盘点 `main` 已有能力，避免重复开发 Trip、Schedule、Finance、Inventory 或 Operator 能力；
3. 找出最小缺口；
4. 冻结 acceptance criteria 与 excluded scope；
5. 由 Owner 单独授权 business-code change / merge / deployment / real data。

在此之前不得把 Demo fixture 当成生产规则，也不得启动真实数据迁移或外部渠道集成。

## GitHub 治理状态

当前没有正式 Tag 或 GitHub Release。`main` 在本次治理对齐开始时也没有 GitHub branch protection/ruleset；平台级保护属于后续单独的 repository-hardening 工作。

远端 `codex/boatops-d1-g1-demo-deployment` 是**已废弃、已被成功 no-source-change D1 方案取代的实验分支**，不得作为 D1 deployed source 或未来 merge baseline。详见 `.project/BRANCH_LEDGER.md`。

## 许可证状态

许可证组合仍为 `PROPOSED_NOT_FROZEN`：

- 服务端核心候选：`AGPL-3.0-only`；
- OpenAPI、JSON Schema、SDK、CLI 和示例客户端候选：`Apache-2.0`。

当前仓库没有正式 `LICENSE`，不能把公开可见代码视为已经按上述候选许可证正式发行。

## 安全与数据

禁止提交 API Key、Token、密码、Cookie、Webhook Secret、数据库连接串、服务器登录信息、真实客户资料、合同、报价、真实财务流水或生产备份。公开示例必须使用人工虚构数据。
