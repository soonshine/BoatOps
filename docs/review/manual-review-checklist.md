# BoatOps 人工复核清单

## 已完成的技术门禁

- [x] Inventory Provider API 7 个公开端点与独立 Operations API 契约。
- [x] 所有公开写命令要求 `Idempotency-Key`，Token 只存 SHA-256 哈希。
- [x] PostgreSQL 16 migration、`btree_gist`、`tstzrange` 与 `allocations_no_active_overlap`。
- [x] 100 个不同幂等键竞争同一档只有一个成功。
- [x] 100 个相同幂等键并发只产生一个逻辑 HOLD。
- [x] BLOCK/HOLD 和 Booking 改期/新 HOLD 真实 HTTP 竞态。
- [x] HOLD expiry worker 与 Booking 确认的过期/未过期竞态。
- [x] BLOCK 创建/解除以及 `operations.write` scope。
- [x] Trip prepare/depart/return/complete、船员与必检项门禁。
- [x] Booking 私有价格快照；公开 API/Outbox 金额与 PII 隔离。
- [x] PostgreSQL 最终备份、隔离恢复、13 表指纹和恢复后排斥约束。
- [x] 版本化 Nginx/PHP-FPM/queue/scheduler 候选部署。
- [x] DNS、TLS 1.2/1.3、HTTP→HTTPS、HSTS 与证书自动续期。
- [x] 首页、两套 API 文档、鉴权与公开隐私边界公网 QA。
- [x] 390×844 无横向溢出、主操作 48px 高、无 console error。

## 必须人工确认的业务规则

- [ ] 冻结真实 BLOCK 原因枚举、强制解除权限与审批历史。
- [ ] 冻结普吉/苏梅真实运营 buffer、HOLD TTL、Plan B 时段和异常补录规则。
- [ ] 提供真实船员身份、岗位、证照及检查表；不得从 Demo 推断。
- [ ] 冻结真实币种、售价、税费、佣金、汇率来源、有效期和 `RATE_CHANGED` 容差。
- [ ] 提供 Google Sheet 只读源或完整 XLSX，完成导入冲突报告和逐单 reconciliation。
- [ ] 决定正式限流阈值、日志留存周期、secret rotation 和告警责任人。

## 阻止正式公开发行

- [ ] 冻结正式名称、商标和 BoatOps/OpenAPI/SDK 许可证组合。
- [ ] 完成独立依赖、secret、PII、许可证和渗透扫描。
- [ ] 重建不含 `.phpunit.result.cache` 与运行时 bootstrap cache 的不可变归档。
- [ ] 由第二家独立运营商仅凭文档完成安装和首笔虚构订单。
- [ ] 人工批准正式版本号、Git commit、Tag 和公开 Release。

## Git 边界

- [x] 已保留并复核开发开始前的 `README.md` 边界内容。
- [x] 用户已于 2026-08-02 明确批准本次候选源码 commit 与 push。
- [ ] Git Tag、GitHub Release 和正式许可证发行仍需单独人工批准。
