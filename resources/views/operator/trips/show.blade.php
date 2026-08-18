@extends('operator.layout')

@section('title', '执行任务 '.$trip->id)

@section('head')
<style>
.trip-page { width: min(100%, 1080px); margin: 0 auto; }
.trip-back { display: inline-flex; margin-bottom: .7rem; color: #526b82; font-size: .82rem; font-weight: 760; text-decoration: none; }
.trip-header { display: flex; flex-wrap: wrap; align-items: start; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }
.trip-header h1 { margin: 0; font-size: clamp(1.55rem, 4vw, 2.25rem); }
.trip-header p { margin: .3rem 0 0; color: #627d98; }
.trip-state { display: grid; gap: .35rem; justify-items: end; }
.trip-status, .trip-next { display: inline-flex; width: fit-content; padding: .3rem .58rem; border-radius: 999px; font-size: .75rem; font-weight: 850; }
.trip-status { background: #edf2f7; color: #334e68; }
.trip-next { background: #e0f2fe; color: #075985; }
.trip-facts { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .7rem; margin: 0 0 1rem; }
.trip-fact { min-width: 0; padding: .8rem .85rem; border: 1px solid #d7e1eb; border-radius: .72rem; background: #fff; }
.trip-fact.wide { grid-column: span 2; }
.trip-fact dt { color: #627d98; font-size: .68rem; font-weight: 820; }
.trip-fact dd { margin: .12rem 0 0; color: #17324d; font-size: .88rem; font-weight: 730; overflow-wrap: anywhere; }
.trip-fact small, .internal-meta { display: block; margin-top: .08rem; color: #71879b; font-size: .72rem; font-weight: 600; }
.trip-section { margin: 1rem 0; padding: 1rem; border: 1px solid #d7e1eb; border-radius: .82rem; background: #fff; }
.trip-section h2 { margin: 0 0 .7rem; font-size: 1.08rem; }
.trip-section h3 { margin: 1rem 0 .55rem; font-size: .95rem; }
.trip-section p { color: #526b82; }
.trip-progress { display: flex; flex-wrap: wrap; gap: .45rem; margin-bottom: .75rem; }
.progress-chip { display: inline-flex; padding: .3rem .55rem; border-radius: 999px; background: #f1f5f9; color: #40566d; font-size: .75rem; font-weight: 800; }
.progress-chip.ready { background: #ecfdf3; color: #166534; }
.progress-chip.pending { background: #fff7ed; color: #9a3412; }
.trip-action-panel { border-color: #bae6fd; background: #f8fdff; }
.trip-action-panel h2 { color: #075985; }
.trip-action-panel button { min-width: 9rem; }
.trip-prep-form fieldset { background: #f8fafc; }
.trip-prep-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .65rem; }
.trip-prep-grid label { margin: 0; }
.trip-prep-grid input { width: 100%; }
.trip-remove { border-color: #cbd5e1; background: #fff; color: #475569; }
.trip-add { margin: .45rem 0; border-color: #94a3b8; background: #fff; color: #334155; }
.trip-note { padding: .7rem .8rem; border-radius: .6rem; background: #f8fafc; color: #526b82; white-space: pre-line; }
.trip-warning { padding: .65rem .75rem; border: 1px solid #fed7aa; border-radius: .6rem; background: #fff7ed; color: #9a3412 !important; font-size: .84rem; font-weight: 720; }
@media (max-width: 760px) {
    .trip-state { justify-items: start; }
    .trip-facts { grid-template-columns: minmax(0, 1fr); }
    .trip-fact.wide { grid-column: auto; }
    .trip-prep-grid { grid-template-columns: minmax(0, 1fr); }
}
</style>
@endsection

@section('content')
<main class="trip-page">
<a class="trip-back" href="{{ route('operator.today') }}">← 返回今日运营</a>
<header class="trip-header">
<div>
<h1>{{ $trip->booking_reference ?: '任务 #'.$trip->id }}@if($trip->contact_name) · {{ $trip->contact_name }}@endif</h1>
<p>{{ \App\Support\OperatorUi::dateTimeRange($trip->planned_start, $trip->planned_end, $organization->timezone) }}</p>
</div>
<div class="trip-state">
<span class="trip-status">{{ \App\Support\OperatorUi::status($trip->status) }}</span>
<span class="trip-next">下一步：{{ $nextActionLabel }}</span>
</div>
</header>

<dl class="trip-facts">
<div class="trip-fact"><dt>船只</dt><dd>{{ $trip->boat_name }}</dd></div>
<div class="trip-fact"><dt>客人人数</dt><dd>{{ $trip->party_size !== null ? $trip->party_size.' 人' : '待补充' }}</dd></div>
<div class="trip-fact"><dt>准备状态</dt><dd>{{ $ready ? '已就绪，可出航' : '待准备' }} · {{ $completedRequiredCount }}/{{ $requiredCount }} 必检完成</dd></div>
<div class="trip-fact wide"><dt>路线</dt><dd>{{ $trip->route_summary ?: ($trip->product_name ?: '待补充') }}@if($trip->route_summary && $trip->product_name)<small>任务模板：{{ $trip->product_name }}</small>@endif</dd></div>
<div class="trip-fact"><dt>负责人 / 船员</dt><dd>@forelse($crew as $assignment){{ $assignment->display_name }}@if($assignment->duty)（{{ $assignment->duty }}）@endif{{ !$loop->last ? '、' : '' }}@empty待安排@endforelse</dd></div>
<div class="trip-fact wide"><dt>接客 / 集合</dt><dd>
@if($trip->pickup_required === null)
待确认是否需要接送
@elseif((bool) $trip->pickup_required)
{{ $trip->pickup_time ? substr((string) $trip->pickup_time, 0, 5) : '时间待补充' }} · {{ $trip->meeting_point ?: ($trip->hotel_name ?: '地点待补充') }}@if($trip->room_number) · 房间 {{ $trip->room_number }}@endif
@else
无需接送；{{ $trip->meeting_point ?: '集合地点待补充' }}
@endif
</dd></div>
<div class="trip-fact"><dt>下客 / 服务地点</dt><dd>{{ $trip->service_location ?: '待补充' }}</dd></div>
</dl>

@if($trip->service_notes || $trip->internal_notes)
<section class="trip-section">
<h2>执行备注</h2>
@if($trip->service_notes)<p class="trip-note"><strong>客人 / 服务要求：</strong> {{ $trip->service_notes }}</p>@endif
@if($trip->internal_notes)<p class="trip-note"><strong>内部备注：</strong> {{ $trip->internal_notes }}</p>@endif
</section>
@endif

<section class="trip-section">
<h2>人员与出航准备</h2>
<div class="trip-progress">
<span class="progress-chip {{ $crew->isNotEmpty() ? 'ready' : 'pending' }}">船员 {{ $crew->count() }} 人</span>
<span class="progress-chip {{ $requiredCount > 0 && $requiredCount === $completedRequiredCount ? 'ready' : 'pending' }}">必检 {{ $completedRequiredCount }}/{{ $requiredCount }}</span>
</div>
@if($crew->isNotEmpty())
<table><thead><tr><th>人员</th><th>角色</th><th>本次职责</th></tr></thead><tbody>
@foreach($crew as $assignment)
<tr><td>{{ $assignment->display_name }}<small class="internal-meta">{{ $assignment->external_reference }}</small></td><td>{{ $assignment->role }}</td><td>{{ $assignment->duty }}</td></tr>
@endforeach
</tbody></table>
@else
<p class="trip-warning">尚未安排负责人 / 船员。</p>
@endif

@if($checklist->isNotEmpty())
<h3>必检与准备项</h3>
<table><thead><tr><th>检查项</th><th>必检</th><th>状态</th></tr></thead><tbody>
@foreach($checklist as $item)
<tr><td>{{ $item->label }}<small class="internal-meta">{{ $item->code }}</small></td><td>{{ $item->required ? '是' : '否' }}</td><td>{{ $item->completed ? '已完成' : '待完成' }}</td></tr>
@endforeach
</tbody></table>
@else
<p class="trip-warning">尚未建立出航检查清单。</p>
@endif
</section>

@if($trip->status === 'PLANNED')
@php($formCrewRows = old('crew', $crewRows))
@php($formChecklistRows = old('checklist', $checklistRows))
<section class="trip-section trip-prep-form">
<h2>完成出航准备</h2>
<p>这里只维护本次任务真正需要的人和检查项。保存后替换当前任务的人员安排与准备状态。</p>
<form method="post" action="{{ route('operator.trips.prepare', $trip->id) }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $prepareIdempotencyKey }}">
<h3>负责人 / 船员</h3>
<div data-rows="crew">
@foreach($formCrewRows as $index => $row)
<fieldset data-row>
<div class="trip-prep-grid">
<label>员工编号（内部） <input name="crew[{{ $index }}][external_reference]" value="{{ $row['external_reference'] ?? '' }}" maxlength="255" required></label>
<label>姓名 <input name="crew[{{ $index }}][display_name]" value="{{ $row['display_name'] ?? '' }}" maxlength="255" required></label>
<label>角色 <input name="crew[{{ $index }}][role]" value="{{ $row['role'] ?? '' }}" maxlength="100" required></label>
<label>本次职责 <input name="crew[{{ $index }}][duty]" value="{{ $row['duty'] ?? '' }}" maxlength="100" required></label>
</div>
<button class="trip-remove" type="button" data-remove-row>删除该人员</button>
</fieldset>
@endforeach
</div>
<button class="trip-add" type="button" data-add-row="crew">添加人员</button>

<h3>出航检查</h3>
<div data-rows="checklist">
@foreach($formChecklistRows as $index => $row)
<fieldset data-row>
<div class="trip-prep-grid">
<label>代码 <input name="checklist[{{ $index }}][code]" value="{{ $row['code'] ?? '' }}" maxlength="100" pattern="[A-Z0-9_-]+" required></label>
<label>检查项 <input name="checklist[{{ $index }}][label]" value="{{ $row['label'] ?? '' }}" maxlength="255" required></label>
</div>
<input type="hidden" name="checklist[{{ $index }}][required]" value="0"><label><input type="checkbox" name="checklist[{{ $index }}][required]" value="1" @checked((bool) ($row['required'] ?? false))> 必检</label>
<input type="hidden" name="checklist[{{ $index }}][completed]" value="0"><label><input type="checkbox" name="checklist[{{ $index }}][completed]" value="1" @checked((bool) ($row['completed'] ?? false))> 已完成</label>
<button class="trip-remove" type="button" data-remove-row>删除该检查项</button>
</fieldset>
@endforeach
</div>
<button class="trip-add" type="button" data-add-row="checklist">添加检查项</button>
<p><button type="submit">保存出航准备</button></p>
</form>
</section>

<section class="trip-section trip-action-panel">
<h2>下一步：登记出航</h2>
@if(!$ready)<p class="trip-warning">必须至少安排一名船员，并完成全部必检项目后才能登记出航。</p>@endif
<form method="post" action="{{ route('operator.trips.depart', $trip->id) }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $departIdempotencyKey }}">
<label>实际出航时间（{{ $organization->timezone }}） <input type="datetime-local" name="departed_at" value="{{ $localNow }}" required></label>
<button type="submit">登记出航</button>
</form>
</section>
@elseif($trip->status === 'DEPARTED')
<section class="trip-section trip-action-panel">
<h2>下一步：登记返航</h2>
<form method="post" action="{{ route('operator.trips.return', $trip->id) }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $returnIdempotencyKey }}">
<label>实际返航时间（{{ $organization->timezone }}） <input type="datetime-local" name="returned_at" value="{{ $localNow }}" required></label>
<button type="submit">登记返航</button>
</form>
</section>
@elseif($trip->status === 'RETURNED')
<section class="trip-section trip-action-panel">
<h2>下一步：完成出航</h2>
<p>确认任务已经返航并完成必要收尾后结束本次执行。</p>
<form method="post" action="{{ route('operator.trips.complete', $trip->id) }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $completeIdempotencyKey }}">
<button type="submit">完成任务</button>
</form>
</section>
@endif

<section class="trip-section">
<h2>订单与联系方式</h2>
<div>订单：<a href="{{ route('operator.bookings.show', $trip->booking_id) }}">打开订单：{{ $trip->booking_reference }}</a> · {{ \App\Support\OperatorUi::status($trip->booking_status) }}</div>
@if($trip->inquiry_id)
<div>联系人：{{ $trip->contact_name ?: '待补充' }}</div>
<div>联系方式：{{ \App\Support\OperatorUi::contactMethod($trip->contact_method) }}{{ $trip->contact_value ? ' / '.$trip->contact_value : '' }}</div>
<div>销售来源：{{ $trip->sales_source ?: '未记录' }}</div>
@endif
</section>

@if($trip->actual_departed_at || $trip->actual_returned_at || $trip->completed_at)
<section class="trip-section">
<h2>执行记录</h2>
<div>实际出航：{{ \App\Support\OperatorUi::dateTime($trip->actual_departed_at, $organization->timezone) }}</div>
<div>实际返航：{{ \App\Support\OperatorUi::dateTime($trip->actual_returned_at, $organization->timezone) }}</div>
<div>完成时间：{{ \App\Support\OperatorUi::dateTime($trip->completed_at, $organization->timezone) }}</div>
</section>
@endif
</main>

@if($trip->status === 'PLANNED')
<template data-template="crew"><fieldset data-row>
<div class="trip-prep-grid">
<label>员工编号（内部） <input name="crew[__INDEX__][external_reference]" maxlength="255" required></label>
<label>姓名 <input name="crew[__INDEX__][display_name]" maxlength="255" required></label>
<label>角色 <input name="crew[__INDEX__][role]" maxlength="100" required></label>
<label>本次职责 <input name="crew[__INDEX__][duty]" maxlength="100" required></label>
</div>
<button class="trip-remove" type="button" data-remove-row>删除该人员</button>
</fieldset></template>
<template data-template="checklist"><fieldset data-row>
<div class="trip-prep-grid">
<label>代码 <input name="checklist[__INDEX__][code]" maxlength="100" pattern="[A-Z0-9_-]+" required></label>
<label>检查项 <input name="checklist[__INDEX__][label]" maxlength="255" required></label>
</div>
<input type="hidden" name="checklist[__INDEX__][required]" value="0"><label><input type="checkbox" name="checklist[__INDEX__][required]" value="1" checked> 必检</label>
<input type="hidden" name="checklist[__INDEX__][completed]" value="0"><label><input type="checkbox" name="checklist[__INDEX__][completed]" value="1"> 已完成</label>
<button class="trip-remove" type="button" data-remove-row>删除该检查项</button>
</fieldset></template>
<script>
function nextRowIndex(type) {
    const container = document.querySelector('[data-rows="' + type + '"]');
    const indexes = Array.from(container.querySelectorAll('input[name]'))
        .map(function (input) {
            const match = input.name.match(new RegExp('^' + type + '\\[(\\d+)\\]'));
            return match ? Number(match[1]) : null;
        })
        .filter(function (index) { return Number.isInteger(index); });

    return indexes.length === 0 ? 0 : Math.max(...indexes) + 1;
}

const nextRowIndexes = { crew: nextRowIndex('crew'), checklist: nextRowIndex('checklist') };

document.addEventListener('click', function (event) {
    const addButton = event.target.closest('[data-add-row]');
    if (addButton) {
        const type = addButton.dataset.addRow;
        const container = document.querySelector('[data-rows="' + type + '"]');
        const template = document.querySelector('[data-template="' + type + '"]');
        const index = nextRowIndexes[type];
        nextRowIndexes[type] += 1;
        container.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(index)));
    }
    const removeButton = event.target.closest('[data-remove-row]');
    if (removeButton) {
        removeButton.closest('[data-row]').remove();
    }
});
</script>
@endif
@endsection
