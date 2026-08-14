@extends('operator.layout')

@section('title', '出航列表')

@section('content')
<h1>出航列表</h1>
<p>运营日期按组织时区 <strong>{{ $organization->timezone }}</strong> 计算。</p>

<section class="card">
<form method="get" action="{{ route('operator.trips.index') }}">
<label>运营日期（组织时区）
<input type="date" name="date" value="{{ $date }}" required>
</label>
<button>查看出航</button>
</form>
</section>

<table>
<thead><tr><th>计划时间</th><th>订单</th><th>船只 / 产品</th><th>联系人 / 人数</th><th>出航状态</th><th>准备状态</th><th>操作</th></tr></thead>
<tbody>
@forelse($trips as $trip)
@php
$crewCount = (int) $trip->crew_count;
$requiredCount = (int) $trip->required_checklist_count;
$completedRequiredCount = (int) $trip->completed_required_count;
$ready = $crewCount > 0 && $requiredCount > 0 && $requiredCount === $completedRequiredCount;
@endphp
<tr>
<td>{{ \App\Support\OperatorUi::dateTimeRange($trip->planned_start, $trip->planned_end, $organization->timezone) }}</td>
<td>{{ $trip->booking_reference }}</td>
<td>{{ $trip->boat_name }}<br>{{ $trip->product_name }}</td>
<td>{{ $trip->contact_name ?: '未提供' }}<br>人数：{{ $trip->party_size ?: '未提供' }}</td>
<td>{{ \App\Support\OperatorUi::status($trip->status) }}</td>
<td>船员：{{ $crewCount }} 人<br>必检项：{{ $completedRequiredCount }}/{{ $requiredCount }} 已完成<br>系统提示：{{ $ready ? '已就绪' : '待准备' }}</td>
<td><a href="{{ route('operator.trips.show', $trip->id) }}">查看出航</a></td>
</tr>
@empty
<tr><td colspan="7">该运营日期暂无计划出航。</td></tr>
@endforelse
</tbody>
</table>

{{ $trips->links() }}
@endsection