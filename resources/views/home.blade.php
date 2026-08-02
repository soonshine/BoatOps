<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>BoatOps Community · 0.0.1 local alpha</title>
    <style>
        :root {
            --cds-background: #ffffff;
            --cds-layer-01: #f4f4f4;
            --cds-layer-hover: #e8e8e8;
            --cds-text-primary: #161616;
            --cds-text-secondary: #525252;
            --cds-border-subtle: #c6c6c6;
            --cds-button-primary: #0f62fe;
            --cds-button-hover: #0043ce;
            --cds-support-success: #198038;
            --cds-support-warning: #f1c21b;
            --cds-support-info: #0f62fe;
        }
        * { box-sizing: border-box; }
        html { color-scheme: light; }
        body {
            margin: 0;
            background: var(--cds-background);
            color: var(--cds-text-primary);
            font-family: "IBM Plex Sans", "Segoe UI", "Microsoft YaHei", system-ui, sans-serif;
            line-height: 1.5;
        }
        a { color: inherit; }
        .masthead {
            min-height: 48px;
            padding: 0 32px;
            background: #161616;
            color: #f4f4f4;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        .brand { font-weight: 600; letter-spacing: .16px; }
        .masthead small { color: #c6c6c6; font-family: ui-monospace, monospace; }
        .shell { max-width: 1280px; margin: 0 auto; padding: 64px 32px 80px; }
        .eyebrow {
            margin: 0 0 16px;
            color: var(--cds-button-primary);
            font: 600 14px/1.3 ui-monospace, monospace;
            letter-spacing: .32px;
            text-transform: uppercase;
        }
        h1 { margin: 0; font-size: clamp(40px, 7vw, 72px); line-height: 1.06; font-weight: 300; }
        .lead { max-width: 760px; margin: 24px 0 0; color: var(--cds-text-secondary); font-size: 18px; }
        .status-row { display: flex; flex-wrap: wrap; gap: 8px; margin: 32px 0; }
        .tag { padding: 5px 10px; border-radius: 999px; background: #edf5ff; color: #0043ce; font-size: 12px; }
        .tag.warning { background: #fff8e1; color: #684e00; }
        .actions { display: flex; flex-wrap: wrap; gap: 8px; }
        .button {
            min-height: 48px;
            padding: 13px 52px 13px 16px;
            display: inline-flex;
            align-items: center;
            background: var(--cds-button-primary);
            color: white;
            text-decoration: none;
            font-size: 14px;
            position: relative;
        }
        .button::after { content: "↗"; position: absolute; right: 16px; }
        .button:hover, .button:focus-visible { background: var(--cds-button-hover); }
        .button.secondary { background: #393939; }
        .section { margin-top: 64px; }
        .section h2 { margin: 0 0 24px; font-size: 32px; font-weight: 400; }
        .grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1px; background: var(--cds-border-subtle); }
        .tile { min-height: 176px; padding: 24px 16px; background: var(--cds-layer-01); }
        .tile .number { display: block; margin-bottom: 28px; font: 400 34px/1 ui-monospace, monospace; }
        .tile strong { display: block; margin-bottom: 8px; font-weight: 600; }
        .tile p { margin: 0; color: var(--cds-text-secondary); font-size: 14px; letter-spacing: .16px; }
        .boundary { border-left: 4px solid var(--cds-support-warning); padding: 24px; background: var(--cds-layer-01); }
        .boundary h3 { margin: 0 0 12px; font-size: 20px; font-weight: 600; }
        .boundary ul { margin: 0; padding-left: 20px; color: var(--cds-text-secondary); }
        .endpoint-list { border-top: 1px solid var(--cds-border-subtle); }
        .endpoint { display: grid; grid-template-columns: 80px minmax(0, 1fr); gap: 16px; padding: 16px 0; border-bottom: 1px solid var(--cds-border-subtle); }
        .method { color: var(--cds-support-success); font: 600 13px/1.6 ui-monospace, monospace; }
        code { overflow-wrap: anywhere; font-family: ui-monospace, monospace; }
        footer { margin-top: 64px; color: var(--cds-text-secondary); font-size: 12px; letter-spacing: .32px; }
        @media (max-width: 800px) {
            .grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 520px) {
            .masthead { padding: 0 16px; }
            .masthead small { display: none; }
            .shell { padding: 40px 16px 56px; }
            .lead { font-size: 16px; }
            .actions { display: grid; grid-template-columns: 1fr; }
            .button { width: 100%; }
            .grid { grid-template-columns: 1fr; }
            .tile { min-height: 144px; }
            .section { margin-top: 48px; }
            .section h2 { font-size: 28px; }
            .endpoint { grid-template-columns: 64px minmax(0, 1fr); gap: 8px; }
        }
    </style>
</head>
<body>
<header class="masthead">
    <span class="brand">BoatOps Community</span>
    <small>inventory authority / local review</small>
</header>
<main class="shell">
    <p class="eyebrow">库存与运营事实源</p>
    <h1>0.0.1 local alpha</h1>
    <p class="lead">首个可运行候选版聚焦船期、HOLD、确认、改期、取消、组织隔离、幂等和库存 revision。BoatOps 对所有库存冲突拥有最终裁决权。</p>
    <div class="status-row" aria-label="发布状态">
        <span class="tag">LOCAL ALPHA</span>
        <span class="tag warning">NOT DEPLOYED</span>
        <span class="tag warning">NOT RELEASED</span>
        <span class="tag warning">LICENSE NOT FROZEN</span>
    </div>
    <div class="actions">
        <a class="button" href="/api-docs">API 契约文档</a>
        <a class="button secondary" href="/up">健康检查</a>
    </div>

    <section class="section" aria-labelledby="evidence-title">
        <h2 id="evidence-title">本版可检查内容</h2>
        <div class="grid">
            <article class="tile"><span class="number">7</span><strong>库存 API 端点</strong><p>查档、HOLD、释放、确认、改期、取消、revision。</p></article>
            <article class="tile"><span class="number">8</span><strong>公开事件 Schema</strong><p>最小同步 payload，不暴露客户 PII、成本或利润。</p></article>
            <article class="tile"><span class="number">UTC</span><strong>时间存储</strong><p>业务区间采用半开区间，组织时区单独返回。</p></article>
            <article class="tile"><span class="number">1×</span><strong>幂等业务写入</strong><p>相同命令和请求只产生一笔业务事实。</p></article>
        </div>
    </section>

    <section class="section" aria-labelledby="boundary-title">
        <h2 id="boundary-title">明确边界</h2>
        <div class="boundary">
            <h3>这是本地候选，不是 Ayany 生产系统</h3>
            <ul>
                <li>不包含真实船只、客户、订单、价格、合同、财务或生产凭据。</li>
                <li>未修改 WordPress、Google Sheet、ChannelHub、服务器或 OTA。</li>
                <li>Plan B 时段、周转缓冲和 HOLD 默认时长仍为外部业务输入，不在源码中补造。</li>
                <li>本地自动化测试使用 SQLite；正式候选仍需 PostgreSQL 排斥约束与并发门禁。</li>
            </ul>
        </div>
    </section>

    <section class="section" aria-labelledby="endpoint-title">
        <h2 id="endpoint-title">Inventory Provider API v1</h2>
        <div class="endpoint-list">
            <div class="endpoint"><span class="method">POST</span><code>/api/v1/availability:check</code></div>
            <div class="endpoint"><span class="method">POST</span><code>/api/v1/holds</code></div>
            <div class="endpoint"><span class="method">POST</span><code>/api/v1/holds/{id}:release</code></div>
            <div class="endpoint"><span class="method">POST</span><code>/api/v1/bookings:confirm</code></div>
            <div class="endpoint"><span class="method">POST</span><code>/api/v1/bookings/{id}:amend</code></div>
            <div class="endpoint"><span class="method">POST</span><code>/api/v1/bookings/{id}:cancel</code></div>
            <div class="endpoint"><span class="method">GET</span><code>/api/v1/inventory/revision</code></div>
        </div>
    </section>

    <footer>BoatOps Community · local verification surface · noindex</footer>
</main>
</body>
</html>
