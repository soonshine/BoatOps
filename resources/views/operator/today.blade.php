@extends('operator.layout')

@section('title', '今日运营')
@section('bodyClass', 'today-operations-body')

@section('head')
<style>
.today-operations-body,
.today-operations-body * { box-sizing: border-box; }
.today-operations-body {
    margin: 0;
    max-width: 100%;
    overflow-x: hidden;
    background: #f3f6fa;
    color: #172b3f;
    line-height: 1.5;
}
.today-operations-body .operator-nav {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: .35rem;
    padding: .75rem clamp(.75rem, 3vw, 2rem);
    border-bottom: 1px solid #d8e2ec;
    background: #fff;
    box-shadow: 0 1px 3px rgb(15 23 42 / 6%);
}
.today-operations-body .operator-nav a,
.today-operations-body .operator-nav button {
    display: inline-flex;
    min-height: 2.35rem;
    align-items: center;
    padding: .45rem .72rem;
    border: 0;
    border-radius: .55rem;
    background: transparent;
    color: #40566d;
    font: inherit;
    font-size: .86rem;
    font-weight: 750;
    text-decoration: none;
    cursor: pointer;
}
.today-operations-body .operator-nav a[aria-current="page"] {
    background: #e0f2fe;
    color: #075985;
}
.today-operations-body .operator-nav form { margin: 0 0 0 auto; }
.today-page {
    width: min(100%, 1280px);
    margin: 0 auto;
    padding: clamp(1rem, 3vw, 2rem);
}
.today-page-header {
    display: flex;
    align-items: end;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
}
.today-eyebrow {
    margin: 0 0 .2rem;
    color: #526b82;
    font-size: .76rem;
    font-weight: 850;
    letter-spacing: .06em;
}
.today-page-header h1 {
    margin: 0;
    color: #102a43;
    font-size: clamp(1.8rem, 5vw, 2.75rem);
    line-height: 1.08;
    letter-spacing: -.035em;
}
.today-subtitle {
    margin: .5rem 0 0;
    color: #526b82;
    font-size: .92rem;
}
.today-date-chip {
    flex: 0 0 auto;
    padding: .62rem .85rem;
    border: 1px solid #cbd8e6;
    border-radius: .7rem;
    background: #fff;
    color: #334e68;
    font-size: .88rem;
    font-weight: 800;
    white-space: nowrap;
}
.today-summary-grid {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: .7rem;
    margin: 0 0 1rem;
}
.today-summary-card {
    min-width: 0;
    padding: .82rem .9rem;
    border: 1px solid #d7e1eb;
    border-top: 4px solid var(--summary-accent, #64748b);
    border-radius: .8rem;
    background: #fff;
    box-shadow: 0 4px 14px rgb(15 23 42 / 5%);
}
.today-summary-card dt {
    color: #526b82;
    font-size: .72rem;
    font-weight: 850;
    line-height: 1.3;
}
.today-summary-card dd {
    margin: .18rem 0 0;
    color: #102a43;
    font-size: 1.65rem;
    font-variant-numeric: tabular-nums;
    font-weight: 850;
    line-height: 1;
}
.summary-total { --summary-accent: #475569; }
.summary-planned { --summary-accent: #d97706; }
.summary-departed { --summary-accent: #2563eb; }
.summary-returned { --summary-accent: #7c3aed; }
.summary-completed { --summary-accent: #15803d; }
.summary-attention { --summary-accent: #dc2626; }
.today-section {
    margin-top: 1rem;
    padding: clamp(.9rem, 2vw, 1.2rem);
    border: 1px solid #d7e1eb;
    border-radius: .95rem;
    background: #fff;
    box-shadow: 0 6px 18px rgb(15 23 42 / 5%);
}
.today-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .8rem;
    margin-bottom: .85rem;
}
.today-section-header h2 {
    margin: 0;
    color: #102a43;
    font-size: 1.18rem;
}
.today-section-header p {
    margin: .18rem 0 0;
    color: #627d98;
    font-size: .82rem;
}
.section-count {
    display: inline-flex;
    min-width: 2rem;
    height: 2rem;
    align-items: center;
    justify-content: center;
    padding: 0 .55rem;
    border-radius: 999px;
    background: #edf2f7;
    color: #334e68;
    font-size: .8rem;
    font-weight: 850;
}
.today-attention-section.has-attention {
    border-color: #fecaca;
    background: #fffafa;
}
.today-attention-section.has-attention .section-count {
    background: #fee2e2;
    color: #991b1b;
}
.today-clear-state,
.today-empty-state {
    margin: 0;
    padding: 1.15rem;
    border: 1px dashed #bfd0df;
    border-radius: .75rem;
    background: #f8fafc;
    color: #526b82;
    text-align: center;
}
.today-clear-state strong,
.today-empty-state strong {
    display: block;
    margin-bottom: .2rem;
    color: #29475f;
}
.today-attention-list {
    display: grid;
    gap: .7rem;
}
.attention-card {
    padding: .9rem;
    border: 1px solid #fecaca;
    border-left: 5px solid #dc2626;
    border-radius: .75rem;
    background: #fff;
}
.attention-card header,
.task-card-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: .55rem;
}
.attention-time {
    color: #334e68;
    font-size: .84rem;
    font-weight: 800;
}
.attention-card h3 {
    margin: .45rem 0 0;
    color: #172b3f;
    font-size: 1rem;
    overflow-wrap: anywhere;
}
.attention-card ul {
    margin: .55rem 0 0;
    padding-left: 1.25rem;
    color: #7f1d1d;
    font-size: .86rem;
}
.attention-card li + li { margin-top: .18rem; }
.attention-card-meta {
    margin: .25rem 0 0;
    color: #627d98;
    font-size: .8rem;
    overflow-wrap: anywhere;
}
.status-badge,
.attention-badge,
.link-state {
    display: inline-flex;
    width: fit-content;
    align-items: center;
    gap: .35rem;
    padding: .25rem .52rem;
    border-radius: 999px;
    font-size: .7rem;
    font-weight: 850;
    line-height: 1.25;
    white-space: nowrap;
}
.status-badge::before {
    width: .42rem;
    height: .42rem;
    border-radius: 50%;
    background: currentColor;
    content: '';
}
.status-planned { background: #fffbeb; color: #92400e; }
.status-departed { background: #eff6ff; color: #1d4ed8; }
.status-returned { background: #f5f3ff; color: #6d28d9; }
.status-completed { background: #ecfdf3; color: #166534; }
.status-cancelled { background: #f1f5f9; color: #475569; }
.attention-badge { background: #fee2e2; color: #991b1b; }
.today-task-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: .85rem;
}
.today-task-card {
    min-width: 0;
    overflow: hidden;
    border: 1px solid #d7e1eb;
    border-radius: .85rem;
    background: #fff;
    box-shadow: 0 4px 14px rgb(15 23 42 / 5%);
}
.today-task-card.needs-attention { border-color: #fca5a5; }
.task-card-header {
    padding: .75rem .85rem;
    border-bottom: 1px solid #e2e8f0;
    background: #f8fafc;
}
.task-id-label {
    display: block;
    color: #627d98;
    font-size: .68rem;
    font-weight: 800;
    letter-spacing: .05em;
}
.task-id {
    display: block;
    color: #102a43;
    font-size: 1.05rem;
    line-height: 1.2;
}
.task-badges {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: .35rem;
}
.task-time {
    padding: .82rem .85rem;
    border-bottom: 1px solid #edf2f7;
}
.task-time span {
    display: block;
    color: #627d98;
    font-size: .7rem;
    font-weight: 800;
}
.task-time time {
    display: block;
    margin-top: .15rem;
    color: #0f3a5d;
    font-size: 1rem;
    font-variant-numeric: tabular-nums;
    font-weight: 850;
}
.task-details {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: .7rem;
    margin: 0;
    padding: .85rem;
}
.task-details > div { min-width: 0; }
.task-details .detail-wide { grid-column: 1 / -1; }
.task-details dt {
    color: #627d98;
    font-size: .68rem;
    font-weight: 800;
}
.task-details dd {
    margin: .13rem 0 0;
    color: #243b53;
    font-size: .86rem;
    font-weight: 700;
    overflow-wrap: anywhere;
}
.task-details dd small {
    display: block;
    margin-top: .1rem;
    color: #71879b;
    font-weight: 600;
}
.task-details a { color: #075985; text-underline-offset: .16rem; }
.execution-times {
    display: flex;
    flex-wrap: wrap;
    gap: .35rem .8rem;
    margin: 0;
    padding: 0 .85rem .8rem;
    color: #526b82;
    font-size: .74rem;
}
.link-states {
    display: flex;
    flex-wrap: wrap;
    gap: .35rem;
    padding: .7rem .85rem;
    border-top: 1px solid #edf2f7;
    background: #fbfdff;
}
.link-state { background: #edf2f7; color: #40566d; }
.task-actions,
.attention-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .5rem;
    margin-top: .65rem;
}
.task-actions {
    margin-top: 0;
    padding: .75rem .85rem;
    border-top: 1px solid #edf2f7;
}
.today-action {
    display: inline-flex;
    min-height: 2.35rem;
    align-items: center;
    justify-content: center;
    padding: .48rem .72rem;
    border: 1px solid #075985;
    border-radius: .55rem;
    background: #075985;
    color: #fff;
    font-size: .78rem;
    font-weight: 800;
    text-decoration: none;
}
.today-action.secondary { background: #fff; color: #075985; }
.action-unavailable {
    align-self: center;
    color: #991b1b;
    font-size: .76rem;
    font-weight: 750;
}
.today-action:focus-visible,
.today-operations-body .operator-nav a:focus-visible,
.today-operations-body .operator-nav button:focus-visible {
    outline: 3px solid #38bdf8;
    outline-offset: 2px;
}
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
}
@media (max-width: 980px) {
    .today-summary-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (max-width: 720px) {
    .today-operations-body .operator-nav { padding: .6rem .65rem; }
    .today-operations-body .operator-nav form { margin-left: 0; }
    .today-page { padding: .85rem .7rem 1.5rem; }
    .today-page-header { align-items: start; flex-direction: column; }
    .today-date-chip { white-space: normal; }
    .today-summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .55rem; }
    .today-summary-card { padding: .72rem; }
    .today-task-grid { grid-template-columns: minmax(0, 1fr); }
    .today-section { padding: .8rem; }
    .today-section-header { align-items: start; }
    .task-details { grid-template-columns: minmax(0, 1fr); }
    .task-details .detail-wide { grid-column: auto; }
    .task-actions .today-action { flex: 1 1 9rem; }
}
</style>
@endsection

@section('content')
<main class="today-page">
<header class="today-page-header">
<div>
<p class="today-eyebrow">今日船务执行</p>
<h1>今日运营</h1>
<p class="today-subtitle">打开即看今天的出航、执行状态与需要处理的关联异常。</p>
</div>
<time class="today-date-chip" datetime="{{ $date }}">{{ $dateLabel }} · {{ $organization->timezone }}</time>
</header>

<section aria-labelledby="today-summary-heading">
<h2 id="today-summary-heading" class="sr-only">今日摘要</h2>
<dl class="today-summary-grid">
<div class="today-summary-card summary-total"><dt>今日任务总数</dt><dd>{{ $summary['total'] }}</dd></div>
<div class="today-summary-card summary-planned"><dt>待出航</dt><dd>{{ $summary['planned'] }}</dd></div>
<div class="today-summary-card summary-departed"><dt>已出航</dt><dd>{{ $summary['departed'] }}</dd></div>
<div class="today-summary-card summary-returned"><dt>已返航</dt><dd>{{ $summary['returned'] }}</dd></div>
<div class="today-summary-card summary-completed"><dt>已完成</dt><dd>{{ $summary['completed'] }}</dd></div>
<div class="today-summary-card summary-attention"><dt>需处理</dt><dd>{{ $summary['attention'] }}</dd></div>
</dl>
</section>

<section class="today-section today-attention-section {{ $attentionTrips->isEmpty() ? 'is-clear' : 'has-attention' }}" aria-labelledby="today-attention-heading">
<header class="today-section-header">
<div>
<h2 id="today-attention-heading">需处理事项</h2>
<p>只检查现有数据能可靠判断的船只、订单、库存、停用与执行时间关联。</p>
</div>
<span class="section-count" aria-label="需处理任务数">{{ $summary['attention'] }}</span>
</header>
@if($attentionTrips->isEmpty())
<p class="today-clear-state"><strong>当前没有需处理事项</strong>未对船员、接送等当前无法安全判断的字段做推测。</p>
@else
<div class="today-attention-list">
@foreach($attentionTrips as $trip)
@php($attentionStatusClass = 'status-'.preg_replace('/[^a-z0-9-]/', '-', strtolower($trip->status)))
<article class="attention-card" data-attention-trip-id="{{ $trip->id }}">
<header>
<time class="attention-time" datetime="{{ $trip->planned_start }}">{{ \App\Support\OperatorUi::dateTime($trip->planned_start, $organization->timezone) }}</time>
<span class="status-badge {{ $attentionStatusClass }}">{{ \App\Support\OperatorUi::status($trip->status) }}</span>
</header>
<h3>出航 #{{ $trip->id }} · {{ $trip->booking_reference ?: '订单关联异常' }}</h3>
<p class="attention-card-meta">{{ $trip->boat_name ?: '船只关联异常' }} · {{ $trip->product_name ?: '产品 / 航线关联异常' }}</p>
<ul>
@foreach($trip->attention_reasons as $reason)
<li>{{ $reason }}</li>
@endforeach
</ul>
<div class="attention-actions">
@if($trip->trip_detail_available)
<a class="today-action" href="{{ route('operator.trips.show', $trip->id) }}">进入出航详情处理</a>
@else
<span class="action-unavailable">出航详情因关联异常暂不可打开</span>
@endif
@if($trip->booking_detail_available)
<a class="today-action secondary" href="{{ route('operator.bookings.show', $trip->related_booking_id) }}">查看订单</a>
@elseif($trip->related_booking_id)
<span class="action-unavailable">订单详情因关联异常暂不可打开</span>
@endif
</div>
</article>
@endforeach
</div>
@endif
</section>

<section class="today-section" aria-labelledby="today-list-heading">
<header class="today-section-header">
<div>
<h2 id="today-list-heading">今日任务列表</h2>
<p>按组织时区的计划出航时间由早到晚排列。</p>
</div>
<span class="section-count" aria-label="今日任务数">{{ $summary['total'] }}</span>
</header>
@if($trips->isEmpty())
<p class="today-empty-state"><strong>今天暂无出航任务</strong>确认运营日期无误后，可前往订单或出航工作台继续查看。</p>
@else
<div class="today-task-grid">
@foreach($trips as $trip)
@php($statusClass = 'status-'.preg_replace('/[^a-z0-9-]/', '-', strtolower($trip->status)))
<article @class(['today-task-card', 'needs-attention' => $trip->needs_attention]) data-trip-id="{{ $trip->id }}">
<header class="task-card-header">
<div>
<span class="task-id-label">航次 ID</span>
<strong class="task-id">出航 #{{ $trip->id }}</strong>
</div>
<div class="task-badges">
<span class="status-badge {{ $statusClass }}">{{ \App\Support\OperatorUi::status($trip->status) }}</span>
@if($trip->needs_attention)
<span class="attention-badge">需处理</span>
@endif
</div>
</header>
<div class="task-time">
<span>服务日期 / 计划出航时间（24 小时制）</span>
<time datetime="{{ $trip->planned_start }}">{{ \App\Support\OperatorUi::dateTimeRange($trip->planned_start, $trip->planned_end, $organization->timezone) }}</time>
</div>
<dl class="task-details">
<div>
<dt>订单 ID / 订单号</dt>
<dd>
@if($trip->booking_detail_available)
<a href="{{ route('operator.bookings.show', $trip->related_booking_id) }}">订单 #{{ $trip->related_booking_id }}</a>
<small>{{ $trip->booking_reference }}</small>
@elseif($trip->related_booking_id)
订单 #{{ $trip->related_booking_id }}<small>{{ $trip->booking_reference ?: '订单详情关联异常' }}</small>
@else
未关联<small>原始关联 ID：#{{ $trip->booking_id }}</small>
@endif
</dd>
</div>
<div><dt>船只</dt><dd>{{ $trip->boat_name ?: '未关联' }}</dd></div>
<div class="detail-wide"><dt>产品 / 航线</dt><dd>{{ $trip->product_name ?: '未关联' }}</dd></div>
<div><dt>客人人数</dt><dd>人数：{{ $trip->party_size !== null ? $trip->party_size : '未提供' }}</dd></div>
</dl>
@if($trip->actual_departed_at || $trip->actual_returned_at || $trip->completed_at)
<p class="execution-times">
@if($trip->actual_departed_at)<span>实际出航：{{ \App\Support\OperatorUi::dateTime($trip->actual_departed_at, $organization->timezone) }}</span>@endif
@if($trip->actual_returned_at)<span>实际返航：{{ \App\Support\OperatorUi::dateTime($trip->actual_returned_at, $organization->timezone) }}</span>@endif
@if($trip->completed_at)<span>完成：{{ \App\Support\OperatorUi::dateTime($trip->completed_at, $organization->timezone) }}</span>@endif
</p>
@endif
<div class="link-states" aria-label="关键关联状态">
<span class="link-state">订单：{{ \App\Support\OperatorUi::status($trip->booking_status) }}</span>
<span class="link-state">库存：{{ \App\Support\OperatorUi::status($trip->allocation_status) }}</span>
<span class="link-state">船只：{{ \App\Support\OperatorUi::status($trip->boat_status) }}</span>
</div>
<footer class="task-actions">
@if($trip->trip_detail_available)
<a class="today-action" href="{{ route('operator.trips.show', $trip->id) }}">进入出航详情</a>
@else
<span class="action-unavailable">出航详情因关联异常暂不可打开</span>
@endif
@if($trip->booking_detail_available)
<a class="today-action secondary" href="{{ route('operator.bookings.show', $trip->related_booking_id) }}">进入订单详情</a>
@elseif($trip->related_booking_id)
<span class="action-unavailable">订单详情因关联异常暂不可打开</span>
@endif
</footer>
</article>
@endforeach
</div>
@endif
</section>
</main>
@endsection
