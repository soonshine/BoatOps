@extends('operator.layout')

@section('title', '今日运营')
@section('bodyClass', 'today-operations-body')

@section('head')
<style>
.today-operations-body .operator-shell-content { padding: 0; }
.today-page { width: min(100%, 1280px); margin: 0 auto; padding: clamp(1rem, 3vw, 2rem); }
.today-page-header { display: flex; align-items: end; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }
.today-eyebrow { margin: 0 0 .2rem; color: #627d98; font-size: .74rem; font-weight: 850; letter-spacing: .06em; }
.today-page-header h1 { margin: 0; font-size: clamp(1.8rem, 5vw, 2.7rem); line-height: 1.08; letter-spacing: -.035em; }
.today-subtitle { margin: .45rem 0 0; color: #526b82; font-size: .92rem; }
.today-date-chip { flex: 0 0 auto; padding: .58rem .78rem; border: 1px solid #cbd8e6; border-radius: .68rem; background: #fff; color: #334e68; font-size: .84rem; font-weight: 800; }
.today-summary-grid { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: .65rem; margin: 0 0 1rem; }
.today-summary-card { min-width: 0; padding: .8rem .85rem; border: 1px solid #d7e1eb; border-top: 4px solid var(--accent, #64748b); border-radius: .78rem; background: #fff; box-shadow: 0 4px 14px rgb(15 23 42 / 5%); }
.today-summary-card dt { color: #526b82; font-size: .7rem; font-weight: 850; }
.today-summary-card dd { margin: .15rem 0 0; color: #102a43; font-size: 1.55rem; font-weight: 880; line-height: 1; }
.summary-preparing { --accent: #d97706; }
.summary-ready { --accent: #0891b2; }
.summary-departed { --accent: #2563eb; }
.summary-returned { --accent: #7c3aed; }
.summary-completed { --accent: #15803d; }
.summary-attention { --accent: #dc2626; }
.today-section { margin-top: 1rem; padding: clamp(.85rem, 2vw, 1.1rem); border: 1px solid #d7e1eb; border-radius: .9rem; background: #fff; box-shadow: 0 5px 16px rgb(15 23 42 / 5%); }
.today-section-header { display: flex; align-items: center; justify-content: space-between; gap: .8rem; margin-bottom: .8rem; }
.today-section-header h2 { margin: 0; font-size: 1.15rem; }
.today-section-header p { margin: .16rem 0 0; color: #627d98; font-size: .8rem; }
.section-count { display: inline-flex; min-width: 2rem; min-height: 2rem; align-items: center; justify-content: center; padding: 0 .55rem; border-radius: 999px; background: #edf2f7; color: #334e68; font-size: .8rem; font-weight: 850; }
.today-attention-section.has-attention { border-color: #fecaca; background: #fffafa; }
.today-attention-section.has-attention .section-count { background: #fee2e2; color: #991b1b; }
.today-clear-state, .today-empty-state { margin: 0; padding: 1rem; border: 1px dashed #bfd0df; border-radius: .72rem; background: #f8fafc; color: #526b82; text-align: center; }
.today-clear-state strong, .today-empty-state strong { display: block; color: #29475f; }
.today-attention-list { display: grid; gap: .65rem; }
.attention-card { padding: .8rem .9rem; border: 1px solid #fecaca; border-left: 5px solid #dc2626; border-radius: .72rem; background: #fff; }
.attention-card header, .task-card-header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem; }
.attention-card h3 { margin: .35rem 0 0; font-size: .98rem; }
.attention-card p { margin: .2rem 0; color: #627d98; font-size: .8rem; }
.attention-card ul { margin: .45rem 0 0; padding-left: 1.25rem; color: #7f1d1d; font-size: .84rem; }
.status-badge, .attention-badge, .gap-badge, .ready-badge { display: inline-flex; width: fit-content; align-items: center; padding: .24rem .5rem; border-radius: 999px; font-size: .7rem; font-weight: 850; line-height: 1.25; }
.status-badge::before { width: .4rem; height: .4rem; margin-right: .32rem; border-radius: 50%; background: currentColor; content: ''; }
.status-planned { background: #fffbeb; color: #92400e; }
.status-departed { background: #eff6ff; color: #1d4ed8; }
.status-returned { background: #f5f3ff; color: #6d28d9; }
.status-completed { background: #ecfdf3; color: #166534; }
.status-cancelled { background: #f1f5f9; color: #475569; }
.attention-badge { background: #fee2e2; color: #991b1b; }
.gap-badge { background: #fff7ed; color: #9a3412; }
.ready-badge { background: #ecfeff; color: #155e75; }
.today-task-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .8rem; }
.today-task-card { min-width: 0; overflow: hidden; border: 1px solid #d7e1eb; border-radius: .85rem; background: #fff; box-shadow: 0 4px 14px rgb(15 23 42 / 5%); }
.today-task-card.needs-attention { border-color: #fca5a5; }
.task-card-header { padding: .75rem .85rem; border-bottom: 1px solid #e2e8f0; background: #f8fafc; }
.task-title { min-width: 0; }
.task-title small { display: block; color: #627d98; font-size: .67rem; font-weight: 800; }
.task-title strong { display: block; overflow-wrap: anywhere; color: #102a43; font-size: 1rem; }
.task-badges { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: .3rem; }
.task-time { padding: .8rem .85rem; border-bottom: 1px solid #edf2f7; }
.task-time span { display: block; color: #627d98; font-size: .68rem; font-weight: 800; }
.task-time time { display: block; margin-top: .1rem; color: #0f3a5d; font-size: 1rem; font-weight: 860; }
.task-details { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .72rem; margin: 0; padding: .85rem; }
.task-details > div { min-width: 0; }
.task-details .wide { grid-column: 1 / -1; }
.task-details dt { color: #627d98; font-size: .67rem; font-weight: 820; }
.task-details dd { margin: .12rem 0 0; color: #243b53; font-size: .86rem; font-weight: 720; overflow-wrap: anywhere; }
.task-details dd small { display: block; margin-top: .08rem; color: #71879b; font-weight: 600; }
.task-gaps { display: flex; flex-wrap: wrap; gap: .35rem; padding: 0 .85rem .75rem; }
.task-notes { margin: 0; padding: 0 .85rem .75rem; color: #526b82; font-size: .78rem; white-space: pre-line; }
.execution-times { display: flex; flex-wrap: wrap; gap: .3rem .8rem; margin: 0; padding: 0 .85rem .75rem; color: #526b82; font-size: .73rem; }
.task-actions, .attention-actions { display: flex; flex-wrap: wrap; gap: .45rem; }
.task-actions { padding: .72rem .85rem; border-top: 1px solid #edf2f7; background: #fbfdff; }
.attention-actions { margin-top: .55rem; }
.today-action { display: inline-flex; min-height: 2.35rem; align-items: center; justify-content: center; padding: .46rem .72rem; border: 1px solid #075985; border-radius: .55rem; background: #075985; color: #fff; font-size: .78rem; font-weight: 820; text-decoration: none; }
.today-action.secondary { background: #fff; color: #075985; }
.action-unavailable { align-self: center; color: #991b1b; font-size: .75rem; font-weight: 750; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; }
@media (max-width: 980px) { .today-summary-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 720px) {
    .today-page { padding: .85rem .65rem 1.5rem; }
    .today-page-header { align-items: start; flex-direction: column; }
    .today-summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .5rem; }
    .today-task-grid { grid-template-columns: minmax(0, 1fr); }
    .task-details { grid-template-columns: minmax(0, 1fr); }
    .task-details .wide { grid-column: auto; }
    .task-actions .today-action { flex: 1 1 9rem; }
}
</style>
@endsection

@section('content')
<main class="today-page">
<header class="today-page-header">
<div>
<p class="today-eyebrow">BOATOPS DASHBOARD</p>
<h1>今日运营</h1>
<p class="today-subtitle">今天 {{ $total }} 个任务。先处理异常，再按时间完成准备、出航、返航和收尾。</p>
</div>
<time class="today-date-chip" datetime="{{ $date }}">{{ $dateLabel }} · {{ $organization->timezone }}</time>
</header>

<section aria-labelledby="today-summary-heading">
<h2 id="today-summary-heading" class="sr-only">今日状态</h2>
<dl class="today-summary-grid">
<div class="today-summary-card summary-preparing"><dt>待准备</dt><dd>{{ $workflowSummary['preparing'] }}</dd></div>
<div class="today-summary-card summary-ready"><dt>准备完成 / 待出航</dt><dd>{{ $workflowSummary['ready'] }}</dd></div>
<div class="today-summary-card summary-departed"><dt>执行中</dt><dd>{{ $workflowSummary['departed'] }}</dd></div>
<div class="today-summary-card summary-returned"><dt>已返航 / 待完成</dt><dd>{{ $workflowSummary['returned'] }}</dd></div>
<div class="today-summary-card summary-completed"><dt>已完成</dt><dd>{{ $workflowSummary['completed'] }}</dd></div>
<div class="today-summary-card summary-attention"><dt>异常需处理</dt><dd>{{ $workflowSummary['attention'] }}</dd></div>
</dl>
</section>

<section class="today-section today-attention-section {{ $attentionTrips->isEmpty() ? 'is-clear' : 'has-attention' }}" aria-labelledby="today-attention-heading">
<header class="today-section-header">
<div>
<h2 id="today-attention-heading">异常优先</h2>
<p>这里只显示会影响数据可信、船只可用或执行状态正确性的异常。</p>
</div>
<span class="section-count">{{ $summary['attention'] }}</span>
</header>
@if($attentionTrips->isEmpty())
<p class="today-clear-state"><strong>当前没有系统识别到的执行异常</strong>仍需按每张任务卡补齐实际运营资料并完成准备。</p>
@else
<div class="today-attention-list">
@foreach($attentionTrips as $trip)
@php($attentionStatusClass = 'status-'.preg_replace('/[^a-z0-9-]/', '-', strtolower($trip->status)))
<article class="attention-card" data-attention-trip-id="{{ $trip->id }}">
<header>
<strong>{{ $trip->booking_reference ?: '出航 #'.$trip->id }}</strong>
<span class="status-badge {{ $attentionStatusClass }}">{{ \App\Support\OperatorUi::status($trip->status) }}</span>
</header>
<p>{{ \App\Support\OperatorUi::dateTimeRange($trip->planned_start, $trip->planned_end, $organization->timezone) }} · {{ $trip->boat_name ?: '船只关联异常' }}</p>
<ul>@foreach($trip->attention_reasons as $reason)<li>{{ $reason }}</li>@endforeach</ul>
<div class="attention-actions">
@if($trip->trip_detail_available)<a class="today-action" href="{{ route('operator.trips.show', $trip->id) }}">进入任务处理</a>@else<span class="action-unavailable">出航详情因关联异常暂不可打开</span>@endif
@if($trip->booking_detail_available)<a class="today-action secondary" href="{{ route('operator.bookings.show', $trip->related_booking_id) }}">查看订单</a>@else<span class="action-unavailable">订单详情因关联异常暂不可打开</span>@endif
</div>
</article>
@endforeach
</div>
@endif
</section>

<section class="today-section" aria-labelledby="today-list-heading">
<header class="today-section-header">
<div>
<h2 id="today-list-heading">今日执行清单</h2>
<p>一张卡就是一个真实任务；按计划出航时间排序。</p>
</div>
<span class="section-count">{{ $total }}</span>
</header>
@if($trips->isEmpty())
<p class="today-empty-state"><strong>今天暂无出航任务</strong>需要新增真实任务时，使用顶部「新建任务」。</p>
@else
<div class="today-task-grid">
@foreach($trips as $trip)
@php($statusClass = 'status-'.preg_replace('/[^a-z0-9-]/', '-', strtolower($trip->status)))
<article @class(['today-task-card', 'needs-attention' => $trip->needs_attention]) data-trip-id="{{ $trip->id }}">
<header class="task-card-header">
<div class="task-title">
<small>订单 / 任务</small>
<strong>{{ $trip->booking_reference ?: '出航 #'.$trip->id }}@if($trip->contact_name) · {{ $trip->contact_name }}@endif</strong>
</div>
<div class="task-badges">
<span class="status-badge {{ $statusClass }}">{{ \App\Support\OperatorUi::status($trip->status) }}</span>
@if($trip->status === 'PLANNED' && $trip->ready)<span class="ready-badge">准备完成</span>@endif
@if($trip->needs_attention)<span class="attention-badge">异常</span>@endif
</div>
</header>

<div class="task-time">
<span>计划出航</span>
<time datetime="{{ $trip->planned_start }}">{{ \App\Support\OperatorUi::dateTimeRange($trip->planned_start, $trip->planned_end, $organization->timezone) }}</time>
</div>

<dl class="task-details">
<div><dt>船只</dt><dd>{{ $trip->boat_name ?: '未分配 / 关联异常' }}</dd></div>
<div><dt>客人人数</dt><dd>{{ $trip->party_size !== null ? '人数：'.$trip->party_size : '待补充' }}</dd></div>
<div class="wide"><dt>路线</dt><dd>{{ $trip->route_summary ?: ($trip->product_name ?: '待补充') }}</dd></div>
<div class="wide"><dt>接客 / 集合</dt><dd>
@if($trip->pickup_required === null)
待确认是否需要接送
@elseif((bool) $trip->pickup_required)
{{ $trip->pickup_time ? substr((string) $trip->pickup_time, 0, 5) : '时间待补充' }} · {{ $trip->meeting_point ?: ($trip->hotel_name ?: '地点待补充') }}
@if($trip->room_number)<small>房间 {{ $trip->room_number }}</small>@endif
@else
无需接送；{{ $trip->meeting_point ?: '集合地点待补充' }}
@endif
</dd></div>
<div class="wide"><dt>负责人 / 船员</dt><dd>
@forelse($trip->crew as $crewMember)
{{ $crewMember->display_name }}@if($crewMember->duty)（{{ $crewMember->duty }}）@endif{{ !$loop->last ? '、' : '' }}
@empty
待安排
@endforelse
</dd></div>
<div><dt>准备</dt><dd>
@if($trip->status === 'PLANNED')
{{ $trip->ready ? '已就绪' : ((int) $trip->completed_required_count.'/'.(int) $trip->required_checklist_count.' 必检完成') }}
@else
{{ (int) $trip->completed_required_count }}/{{ (int) $trip->required_checklist_count }} 必检完成
@endif
</dd></div>
<div><dt>下一步</dt><dd>{{ $trip->next_action_label }}</dd></div>
</dl>

@if($trip->execution_gaps !== [])
<div class="task-gaps" aria-label="待补资料">
@foreach($trip->execution_gaps as $gap)<span class="gap-badge">{{ $gap }}</span>@endforeach
</div>
@endif
@if($trip->service_notes)<p class="task-notes"><strong>服务要求：</strong>{{ $trip->service_notes }}</p>@endif
@if($trip->actual_departed_at || $trip->actual_returned_at || $trip->completed_at)
<p class="execution-times">
@if($trip->actual_departed_at)<span>实际出航 {{ \App\Support\OperatorUi::dateTime($trip->actual_departed_at, $organization->timezone) }}</span>@endif
@if($trip->actual_returned_at)<span>实际返航 {{ \App\Support\OperatorUi::dateTime($trip->actual_returned_at, $organization->timezone) }}</span>@endif
@if($trip->completed_at)<span>完成 {{ \App\Support\OperatorUi::dateTime($trip->completed_at, $organization->timezone) }}</span>@endif
</p>
@endif

<footer class="task-actions">
@if($trip->trip_detail_available)
<a class="today-action" href="{{ route('operator.trips.show', $trip->id) }}">{{ $trip->next_action_label }}</a>
@else
<span class="action-unavailable">出航详情因关联异常暂不可打开</span>
@endif
@if($trip->booking_detail_available)<a class="today-action secondary" href="{{ route('operator.bookings.show', $trip->related_booking_id) }}">订单详情</a>@else<span class="action-unavailable">订单详情因关联异常暂不可打开</span>@endif
</footer>
</article>
@endforeach
</div>
@endif
</section>
</main>
@endsection
