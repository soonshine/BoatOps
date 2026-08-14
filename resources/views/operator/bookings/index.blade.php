@extends('operator.layout')

@section('title', '订单列表')

@section('content')
<h1>订单列表</h1>
<p>服务日期和时间均按组织时区 <strong>{{ $organization->timezone }}</strong> 显示。</p>

<nav aria-label="订单时间范围">
<a href="{{ route('operator.bookings.index', ['view' => 'today']) }}" @if($selectedView === 'today') aria-current="page" @endif>今日订单</a>
<a href="{{ route('operator.bookings.index', ['view' => 'upcoming']) }}" @if($selectedView === 'upcoming') aria-current="page" @endif>未来订单</a>
<a href="{{ route('operator.bookings.index', ['view' => 'all']) }}" @if($selectedView === 'all') aria-current="page" @endif>全部订单</a>
</nav>

<form method="get" action="{{ route('operator.bookings.index') }}" class="card">
<input type="hidden" name="view" value="{{ $selectedView }}">
<label>服务日期（组织时区）
<input type="date" name="date" value="{{ request('date') }}">
</label>
<label>订单状态
<select name="status">
<option value="">全部状态</option>
@foreach($bookingStatuses as $status)
<option value="{{ $status }}" @selected(request('status') === $status)>{{ \App\Support\OperatorUi::status($status) }}</option>
@endforeach
</select>
</label>
<label>订单号、询价号或客人姓名
<input name="q" value="{{ request('q') }}" maxlength="100" placeholder="输入关键词搜索">
</label>
<button>筛选订单</button>
<a href="{{ route('operator.bookings.index', ['view' => $selectedView]) }}">清除筛选</a>
</form>

@if($bookings->isEmpty())
<p>没有符合当前筛选条件的订单。</p>
@else
<table>
<thead>
<tr>
<th>服务日期 / 时间</th>
<th>订单号</th>
<th>船只</th>
<th>产品 / 出航模板</th>
<th>订单状态</th>
<th>客人</th>
<th>人数</th>
<th>出航状态</th>
<th>销售来源</th>
</tr>
</thead>
<tbody>
@foreach($bookings as $booking)
<tr data-booking-id="{{ $booking->id }}">
<td>{{ \App\Support\OperatorUi::dateTimeRange($booking->business_start, $booking->business_end, $organization->timezone) }}</td>
<td><a href="{{ route('operator.bookings.show', $booking->id) }}">{{ $booking->external_reference }}</a></td>
<td>{{ $booking->boat_name }}</td>
<td>{{ $booking->product_name }}</td>
<td>{{ \App\Support\OperatorUi::status($booking->status) }}</td>
<td>{{ $booking->contact_name ?: '未关联客人资料' }}</td>
<td>{{ $booking->party_size ?: '未提供' }}</td>
<td>{{ $booking->trip_status ? \App\Support\OperatorUi::status($booking->trip_status) : '无出航记录' }}</td>
<td>{{ $booking->sales_source ?: '未提供' }}</td>
</tr>
@endforeach
</tbody>
</table>

{{ $bookings->links() }}
@endif
@endsection