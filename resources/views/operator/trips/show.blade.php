@extends('operator.layout')

@section('title', '出航 '.$trip->id)

@section('content')
<h1>出航 #{{ $trip->id }}</h1>
<p>组织时区：{{ $organization->timezone }}</p>

<section class="card">
<h2>出航信息</h2>
<div>出航状态：{{ \App\Support\OperatorUi::status($trip->status) }}</div>
<div>计划时间：{{ \App\Support\OperatorUi::dateTimeRange($trip->planned_start, $trip->planned_end, $organization->timezone) }}</div>
<div>实际出航：{{ \App\Support\OperatorUi::dateTime($trip->actual_departed_at, $organization->timezone) }}</div>
<div>实际返航：{{ \App\Support\OperatorUi::dateTime($trip->actual_returned_at, $organization->timezone) }}</div>
<div>完成时间：{{ \App\Support\OperatorUi::dateTime($trip->completed_at, $organization->timezone) }}</div>
<div>船只：{{ $trip->boat_name }}</div>
<div>产品 / 出航模板：{{ $trip->product_name }}</div>
<div>准备状态：{{ $ready ? '已就绪，可出航' : '待准备' }}</div>
<div>船员：{{ $crew->count() }} 人；必检项：{{ $completedRequiredCount }}/{{ $requiredCount }} 已完成。</div>
</section>

<section class="card">
<h2>订单</h2>
<div>订单号：{{ $trip->booking_reference }}</div>
<div>订单状态：{{ \App\Support\OperatorUi::status($trip->booking_status) }}</div>
<p><a href="{{ route('operator.bookings.show', $trip->booking_id) }}">打开订单</a></p>
</section>

<section class="card">
<h2>运营资料</h2>
@if($trip->inquiry_id)
<div>联系人：{{ $trip->contact_name ?: '未提供' }}</div>
<div>联系方式：{{ \App\Support\OperatorUi::contactMethod($trip->contact_method) }}{{ $trip->contact_value ? ' / '.$trip->contact_value : '' }}</div>
<div>人数：{{ $trip->party_size ?: '未提供' }}</div>
<div>集合地点：{{ $trip->meeting_point ?: '未提供' }}</div>
<div>服务地点 / 下客点：{{ $trip->service_location ?: '未提供' }}</div>
<div>客户 / 服务备注：{{ $trip->service_notes ?: '无' }}</div>
<div>内部运营备注：{{ $trip->internal_notes ?: '无' }}</div>
<div>销售来源：{{ $trip->sales_source ?: '未提供' }}</div>
@else
<p>未关联操作员询价资料。</p>
@endif
</section>

<section class="card">
<h2>船员安排</h2>
<table><thead><tr><th>外部参考号</th><th>显示名称</th><th>角色</th><th>职责</th></tr></thead><tbody>
@forelse($crew as $assignment)
<tr><td>{{ $assignment->external_reference }}</td><td>{{ $assignment->display_name }}</td><td>{{ $assignment->role }}</td><td>{{ $assignment->duty }}</td></tr>
@empty
<tr><td colspan="4">尚未安排船员。</td></tr>
@endforelse
</tbody></table>
</section>

<section class="card">
<h2>检查清单</h2>
<table><thead><tr><th>代码</th><th>检查项</th><th>必检</th><th>已完成</th><th>完成时间</th></tr></thead><tbody>
@forelse($checklist as $item)
<tr><td>{{ $item->code }}</td><td>{{ $item->label }}</td><td>{{ $item->required ? '是' : '否' }}</td><td>{{ $item->completed ? '是' : '否' }}</td><td>{{ $item->completed_at ? \App\Support\OperatorUi::dateTime($item->completed_at, $organization->timezone) : '未完成' }}</td></tr>
@empty
<tr><td colspan="5">暂无检查项。</td></tr>
@endforelse
</tbody></table>
</section>

@if($trip->status === 'PLANNED')
@php($formCrewRows = old('crew', $crewRows))
@php($formChecklistRows = old('checklist', $checklistRows))
<section class="card">
<h2>准备 / 重新准备</h2>
<p>保存后将替换当前出航的船员安排和检查清单准备状态。</p>
<p>登记出航前，必须至少安排一名船员并完成全部必检项目。</p>
<form method="post" action="{{ route('operator.trips.prepare', $trip->id) }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $prepareIdempotencyKey }}">
<h3>船员安排</h3>
<div data-rows="crew">
@foreach($formCrewRows as $index => $row)
<fieldset data-row>
<label>外部参考号 <input name="crew[{{ $index }}][external_reference]" value="{{ $row['external_reference'] ?? '' }}" maxlength="255" required></label>
<label>显示名称 <input name="crew[{{ $index }}][display_name]" value="{{ $row['display_name'] ?? '' }}" maxlength="255" required></label>
<label>角色 <input name="crew[{{ $index }}][role]" value="{{ $row['role'] ?? '' }}" maxlength="100" required></label>
<label>职责 <input name="crew[{{ $index }}][duty]" value="{{ $row['duty'] ?? '' }}" maxlength="100" required></label>
<button type="button" data-remove-row>删除该船员</button>
</fieldset>
@endforeach
</div>
<button type="button" data-add-row="crew">添加船员</button>

<h3>检查清单</h3>
<div data-rows="checklist">
@foreach($formChecklistRows as $index => $row)
<fieldset data-row>
<label>代码 <input name="checklist[{{ $index }}][code]" value="{{ $row['code'] ?? '' }}" maxlength="100" pattern="[A-Z0-9_-]+" required></label>
<label>检查项 <input name="checklist[{{ $index }}][label]" value="{{ $row['label'] ?? '' }}" maxlength="255" required></label>
<input type="hidden" name="checklist[{{ $index }}][required]" value="0"><label><input type="checkbox" name="checklist[{{ $index }}][required]" value="1" @checked((bool) ($row['required'] ?? false))> 必检</label>
<input type="hidden" name="checklist[{{ $index }}][completed]" value="0"><label><input type="checkbox" name="checklist[{{ $index }}][completed]" value="1" @checked((bool) ($row['completed'] ?? false))> 已完成</label>
<button type="button" data-remove-row>删除该检查项</button>
</fieldset>
@endforeach
</div>
<button type="button" data-add-row="checklist">添加检查项</button>
<p><button>保存出航准备</button></p>
</form>
</section>

<section class="card">
<h2>登记出航</h2>
<p>{{ $ready ? '出航准备已完成。' : '船员或检查清单尚未完成；权威操作将拒绝出航。' }}</p>
<form method="post" action="{{ route('operator.trips.depart', $trip->id) }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $departIdempotencyKey }}">
<label>实际出航时间（{{ $organization->timezone }}） <input type="datetime-local" name="departed_at" value="{{ $localNow }}" required></label>
<button>登记出航</button>
</form>
</section>
@elseif($trip->status === 'DEPARTED')
<section class="card">
<h2>登记返航</h2>
<form method="post" action="{{ route('operator.trips.return', $trip->id) }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $returnIdempotencyKey }}">
<label>实际返航时间（{{ $organization->timezone }}） <input type="datetime-local" name="returned_at" value="{{ $localNow }}" required></label>
<button>登记返航</button>
</form>
</section>
@elseif($trip->status === 'RETURNED')
<section class="card">
<h2>完成出航</h2>
<p>完成时间使用当前服务器时间。</p>
<form method="post" action="{{ route('operator.trips.complete', $trip->id) }}">
@csrf
<input type="hidden" name="idempotency_key" value="{{ $completeIdempotencyKey }}">
<button>完成出航</button>
</form>
</section>
@endif

@if($trip->status === 'PLANNED')
<template data-template="crew"><fieldset data-row>
<label>外部参考号 <input name="crew[__INDEX__][external_reference]" maxlength="255" required></label>
<label>显示名称 <input name="crew[__INDEX__][display_name]" maxlength="255" required></label>
<label>角色 <input name="crew[__INDEX__][role]" maxlength="100" required></label>
<label>职责 <input name="crew[__INDEX__][duty]" maxlength="100" required></label>
<button type="button" data-remove-row>删除该船员</button>
</fieldset></template>
<template data-template="checklist"><fieldset data-row>
<label>代码 <input name="checklist[__INDEX__][code]" maxlength="100" pattern="[A-Z0-9_-]+" required></label>
<label>检查项 <input name="checklist[__INDEX__][label]" maxlength="255" required></label>
<input type="hidden" name="checklist[__INDEX__][required]" value="0"><label><input type="checkbox" name="checklist[__INDEX__][required]" value="1" checked> 必检</label>
<input type="hidden" name="checklist[__INDEX__][completed]" value="0"><label><input type="checkbox" name="checklist[__INDEX__][completed]" value="1"> 已完成</label>
<button type="button" data-remove-row>删除该检查项</button>
</fieldset></template>
<script>
function nextRowIndex(type) {
    const container = document.querySelector('[data-rows="' + type + '"]');
    const indexes = Array.from(container.querySelectorAll('input[name]'))
        .map(function (input) {
            const match = input.name.match(new RegExp('^' + type + '\\[(\\d+)\\]'));
            return match ? Number(match[1]) : null;
        })
        .filter(function (index) {
            return Number.isInteger(index);
        });

    return indexes.length === 0 ? 0 : Math.max(...indexes) + 1;
}

const nextRowIndexes = {
    crew: nextRowIndex('crew'),
    checklist: nextRowIndex('checklist'),
};

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