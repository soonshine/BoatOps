# BoatOps 本地虚构演示站

> **LOCAL_WORKTREE / NOT_DEPLOYED / NOT_RELEASED**
>
> 仅包含人工虚构演示数据。不得接入 Google Sheet、生产数据库、真实客户、真实财务、浏览器凭据、ChannelHub、OTA 或 WordPress。

## 安全边界

`/demo` 默认关闭。只有 `BOATOPS_DEMO_SITE_ENABLED=true` 且 Laravel 环境精确为 `local` 或 `testing` 时才可能访问；精确命名的虚构组织或最小权限虚构 actor 缺失、重复、停用或 scope 不足时均返回 404。浏览器不接收 Bearer Token 或 `BOATOPS_DEMO_TOKEN`。

Plan A（虚构演示船）和 Plan B（虚构演示船）属于同一虚构组织，均为整船资源，不是按座位库存。BoatOps 的 `allocations` / `trips` 是本测试站排期查询来源。

页面分区显示近期燃油、费用和库存流水，包括 `POSTED`、`REVERSED` 以及可识别但不可再次冲销的库存 `REVERSAL` 补偿流水。每行显示内部 ID、人工虚构外部引用、组织时区发生时间、金额或数量/币种和状态；已冲销原记录同时显示原因、服务端冲销时间及补偿 movement ID。查询通过每类一次带 `finance_reversals` 的集合查询完成，不按行 N+1 拼接。

只有 `POSTED` 且非补偿流水显示行内 CSRF 冲销表单，原因必填，每行由服务端生成独立 UUID `command_id`。demo 固定分派到正式燃油、费用或库存冲销 controller/service，正式 API payload 仅含生成的 `external_reference` 与 `reason`；更正后应使用现有正常创建表单重录，不修改或删除原金额。浏览器不接收 API token、Bearer secret 或 client secret。

“每日现金活动（只读派生）”按当前虚构组织的每个 `ACTIVE` 现金账户分组。每组显示组织时区今日营业日期、账户名称/类型/币种、流出、流入、净变动和记账笔数，并显示截至下一个本地午夜、向前覆盖 7 个本地营业日、最多 200 条的现金流水。流水顺序保持正式 activity 读 API 的 `occurred_at`、`id` 升序，显示本地发生时间、方向、记账种类、金额/币种、状态、来源类型/ID及原流水/补偿流水关系。

现金区通过 `DemoSiteController` 在 fail-closed middleware 已确定的组织和 actor 上下文中，调用既有 `OperationsCostController::cashAccountDailySummary` 与 `cashAccountActivity` 正式读方法；不新增写路由，也不直接写 `cash_postings`。现金流水仅由燃油、费用、库存 `PURCHASE` 和冲销命令派生；`LOAD / CONSUME / RETURN / WASTE` 等非现金移动不产生现金流水，页面不提供手工现金记账或编辑入口。

## 本地启动

在 BoatOps 仓库中执行：

```bash
cp .env.example .env
php -c 'E:\ayany\BoatOps\.tools\php-test.ini' artisan key:generate
# 在 .env 中设置：
# BOATOPS_DEMO_SITE_ENABLED=true
# BOATOPS_DEMO_TOKEN=至少24字符的仅本地虚构值
php -c 'E:\ayany\BoatOps\.tools\php-test.ini' artisan migrate
php -c 'E:\ayany\BoatOps\.tools\php-test.ini' artisan db:seed --class="Database\Seeders\DemoSiteSeeder"
php -c 'E:\ayany\BoatOps\.tools\php-test.ini' artisan serve
```

浏览器访问 `http://127.0.0.1:8000/demo`。重复运行 `DemoSiteSeeder` 不会复制组织、actor、船、排期、主数据、期初库存或其现金流水。虚构期初 `PURCHASE` 通过正式库存 controller/service 路径生成一条 `STOCK_PURCHASE / OUTFLOW`，不会由 seeder 直接写 `cash_postings`。

也可运行默认 `php -c 'E:\ayany\BoatOps\.tools\php-test.ini' artisan db:seed`；它保留 `BOATOPS_DEMO_TOKEN` 至少 24 字符的门禁并调用同一独立 Seeder。

## 本地验证

```bash
php -c 'E:\ayany\BoatOps\.tools\php-test.ini' vendor/bin/phpunit
php -c 'E:\ayany\BoatOps\.tools\php-test.ini' vendor/laravel/pint/builds/pint --test
npm run test:contract
php -c 'E:\ayany\BoatOps\.tools\php-test.ini' artisan route:list
git diff --check
```

所有演示组织、actor、船、排期、引用及财务/库存流水均为人工虚构。本地 HTTP smoke 已在全新、隔离、纯虚构数据的条件下通过。本说明不声称完成浏览器视觉 QA、PostgreSQL 验证、生产部署、发布或真实数据迁移。
